<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\DocumentNumberGenerator;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Catalog\Models\Item;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Sales\Enums\SalesDocumentStatus;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Domain\Sales\Exceptions\SalesDocumentRefused;
use App\Domain\Sales\Models\SalesDocument;
use App\Domain\Sales\Models\SalesDocumentLine;
use App\Domain\Sales\Services\SalesDocumentCalculator;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create or replace a draft sales document.
 *
 * Only a draft. An issued document is a record: it is corrected by a credit
 * note or a void, never by editing, and the model refuses the update
 * independently of this action.
 *
 * Lines are REPLACED rather than reconciled. A document's lines are a set the
 * user is editing wholesale in a line editor, and diffing them would be more
 * code for the same result — with the added risk of a partial update leaving
 * totals that match neither the old lines nor the new.
 *
 * Everything a line needs is COPIED from the item at this moment: description,
 * price, revenue account, tax. That copy is why a two-year-old invoice stays
 * readable after the item was renamed, repriced or archived.
 *
 * @see ACCOUNTING_RULES.md §5, §6
 */
final readonly class SaveSalesDocument
{
    public function __construct(
        private TenantContext $tenant,
        private DocumentNumberGenerator $numbers,
        private SalesDocumentCalculator $calculator,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function handle(
        SalesDocumentType $type,
        array $attributes,
        array $lines,
        ?SalesDocument $document = null,
        ?User $actor = null,
    ): SalesDocument {
        if ($document !== null && ! $document->status->isEditable()) {
            throw SalesDocumentRefused::notEditable(
                $document->type->label(),
                $document->number,
                $document->status->label(),
            );
        }

        $organization = $this->tenant->organization();

        $contact = Contact::query()->findOrFail(self::text($attributes, 'contact_id'));

        if (! $contact->acceptsDocuments()) {
            throw SalesDocumentRefused::contactUnusable($contact->display_name);
        }

        if (! $contact->kind->isCustomer()) {
            throw SalesDocumentRefused::notACustomer($contact->display_name);
        }

        $issueDate = Carbon::parse(self::text($attributes, 'issue_date'));

        return DB::transaction(function () use (
            $type,
            $attributes,
            $lines,
            $document,
            $actor,
            $organization,
            $contact,
            $issueDate,
        ): SalesDocument {
            $isNew = $document === null;

            if ($isNew) {
                $document = new SalesDocument;

                $document->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->getKey(),
                    'type' => $type,
                    // Claimed at creation, not at issue. A draft the user can
                    // see and refer to needs a number, and the sequence is
                    // gap-free either way.
                    'number' => $this->numbers->next($type->sequenceKey(), $issueDate),
                    'status' => SalesDocumentStatus::Draft,
                    'created_by' => $actor?->getKey(),
                ]);
            }

            /** @var SalesDocument $document */
            $document->forceFill([
                'contact_id' => $contact->getKey(),
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $this->dueDate($type, $attributes, $contact, $issueDate),
                'expires_on' => $this->expiryDate($type, $attributes, $issueDate),
                'reference' => self::optionalText($attributes, 'reference'),
                'notes' => self::optionalText($attributes, 'notes'),
                'terms' => self::optionalText($attributes, 'terms'),
                'discount_type' => self::optionalText($attributes, 'discount_type'),
                'discount_value' => self::optionalText($attributes, 'discount_value'),
                'prices_include_tax' => (bool) ($attributes['prices_include_tax'] ?? false),
                // The contact's currency, falling back to the organisation's.
                // A document's currency is never guessed from the browser.
                'currency' => self::optionalText($attributes, 'currency')
                    ?? $contact->currency
                    ?? $organization->base_currency,
                'exchange_rate' => self::optionalText($attributes, 'exchange_rate') ?? '1',
                /*
                 * Addresses are COPIED, not referenced. A customer who moves
                 * must not silently change where last year's invoices were
                 * sent.
                 */
                'billing_address' => $attributes['billing_address'] ?? $contact->billing_address,
                'shipping_address' => $attributes['shipping_address'] ?? $contact->shipping_address,
            ])->save();

            $this->replaceLines($document, $lines);

            $this->calculator->recalculate($document);

            $this->audit->record(
                action: $isNew ? 'sales.document_created' : 'sales.document_updated',
                subject: $document,
                description: sprintf(
                    '%s %s for %s — %s %s',
                    $document->type->label(),
                    $document->number,
                    $contact->display_name,
                    $document->currency,
                    $document->total,
                ),
                new: [
                    'number' => $document->number,
                    'contact' => $contact->display_name,
                    'total' => $document->total,
                    'lines' => count($lines),
                ],
                actor: $actor,
            );

            return $document->refresh();
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(SalesDocument $document, array $lines): void
    {
        SalesDocumentLine::query()->where('sales_document_id', $document->id)->delete();

        // The fallback for a line with no item and no account of its own.
        // Resolved once rather than per line.
        $defaultRevenue = $this->defaultRevenueAccountId();

        $lineNo = 1;

        foreach ($lines as $input) {
            $item = isset($input['item_id']) && is_string($input['item_id'])
                ? Item::query()->find($input['item_id'])
                : null;

            $line = new SalesDocumentLine;

            $line->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $document->organization_id,
                'sales_document_id' => $document->id,
                'line_no' => $lineNo++,
                'item_id' => $item?->id,
                /*
                 * Everything below prefers what the user typed over what the
                 * item says. The item is a default, and a line the user has
                 * edited must not be quietly reset to it.
                 */
                'description' => self::optionalText($input, 'description')
                    ?? ($item === null ? '' : $item->name),
                'unit' => self::optionalText($input, 'unit') ?? $item?->unit,
                'quantity' => self::optionalText($input, 'quantity') ?? '1',
                'unit_price' => self::optionalText($input, 'unit_price')
                    ?? ($item === null ? null : $item->sale_price)
                    ?? '0',
                'discount_type' => self::optionalText($input, 'discount_type'),
                'discount_value' => self::optionalText($input, 'discount_value'),
                'tax_id' => self::optionalText($input, 'tax_id') ?? $item?->sales_tax_id,
                'revenue_account_id' => self::optionalText($input, 'revenue_account_id')
                    ?? ($item === null ? null : $item->sales_account_id)
                    ?? $defaultRevenue,
                'project_id' => self::optionalText($input, 'project_id'),
                'warehouse_id' => self::optionalText($input, 'warehouse_id'),
            ])->save();
        }
    }

    /**
     * A required string from the input.
     *
     * @param  array<string, mixed>  $input
     */
    private static function text(array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException("A sales document needs a {$key}.");
        }

        return $value;
    }

    /**
     * An optional string, with a blank treated as absent.
     *
     * A form posts an empty string for a field nobody filled in, and storing
     * that rather than null makes "has a reference" true for every document.
     *
     * @param  array<string, mixed>  $input
     */
    private static function optionalText(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * Where revenue lands when neither the line nor the item says.
     *
     * The first operating revenue account by code, which for the standard
     * chart is 4010 Sales Revenue. Deliberately not a system account: an
     * organisation is expected to have several revenue accounts, and picking
     * one as "the" revenue account would be wrong for most of them.
     */
    private function defaultRevenueAccountId(): string
    {
        $account = Account::query()
            ->postable()
            ->where('type', 'income')
            ->whereNull('system_role')
            ->orderBy('code')
            ->first();

        if ($account !== null) {
            return $account->id;
        }

        // No ordinary revenue account at all: an unfinished chart. Rather
        // than fail here, fall back to something that exists so the error
        // surfaces at posting with a message about the chart.
        return Account::query()
            ->where('system_role', SystemAccount::AccountsReceivable->value)
            ->sole()
            ->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function dueDate(
        SalesDocumentType $type,
        array $attributes,
        Contact $contact,
        Carbon $issueDate,
    ): ?string {
        if (! $type->hasDueDate()) {
            return null;
        }

        $given = self::optionalText($attributes, 'due_date');

        if ($given !== null) {
            return Carbon::parse($given)->toDateString();
        }

        // From the contact's own terms, which is the whole reason terms are
        // stored on a contact rather than typed on every invoice.
        return $contact->dueDateFrom($issueDate)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function expiryDate(
        SalesDocumentType $type,
        array $attributes,
        Carbon $issueDate,
    ): ?string {
        if ($type !== SalesDocumentType::Estimate) {
            return null;
        }

        $given = self::optionalText($attributes, 'expires_on');

        if ($given !== null) {
            return Carbon::parse($given)->toDateString();
        }

        // Thirty days, which is long enough to be useful and short enough
        // that a stale quote stops being quoted.
        return $issueDate->copy()->addDays(30)->toDateString();
    }
}
