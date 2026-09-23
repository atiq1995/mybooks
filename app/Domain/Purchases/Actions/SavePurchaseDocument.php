<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Actions;

use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\DocumentNumberGenerator;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Catalog\Models\Item;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Inventory\Services\InventoryAccounts;
use App\Domain\Purchases\Enums\PurchaseDocumentStatus;
use App\Domain\Purchases\Enums\PurchaseDocumentType;
use App\Domain\Purchases\Exceptions\PurchaseDocumentRefused;
use App\Domain\Purchases\Models\PurchaseDocument;
use App\Domain\Purchases\Models\PurchaseDocumentLine;
use App\Domain\Purchases\Services\PurchaseDocumentCalculator;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create or replace a draft purchase document.
 *
 * Only a draft. An approved bill is a record: it is corrected by a vendor
 * credit or a void, and the model refuses the update independently of this
 * action.
 *
 * Lines are REPLACED rather than reconciled, and everything a line needs is
 * COPIED from the item at this moment — the same reasoning as the sales side,
 * and the copy is why a two-year-old bill stays readable.
 *
 * One thing here has no sales counterpart: the duplicate-bill check. It is
 * done in this action rather than only in the form request, because it
 * protects money rather than form state, and an import or an API call must
 * hit it too.
 *
 * @see ACCOUNTING_RULES.md §4.6, §5, §6
 */
final readonly class SavePurchaseDocument
{
    public function __construct(
        private TenantContext $tenant,
        private DocumentNumberGenerator $numbers,
        private PurchaseDocumentCalculator $calculator,
        private InventoryAccounts $inventoryAccounts,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function handle(
        PurchaseDocumentType $type,
        array $attributes,
        array $lines,
        ?PurchaseDocument $document = null,
        ?User $actor = null,
    ): PurchaseDocument {
        if ($document !== null && ! $document->status->isEditable()) {
            throw PurchaseDocumentRefused::notEditable(
                $document->type->label(),
                $document->number,
                $document->status->label(),
            );
        }

        $organization = $this->tenant->organization();

        $contact = Contact::query()->findOrFail(self::text($attributes, 'contact_id'));

        if (! $contact->acceptsDocuments()) {
            throw PurchaseDocumentRefused::contactUnusable($contact->display_name);
        }

        if (! $contact->kind->isVendor()) {
            throw PurchaseDocumentRefused::notAVendor($contact->display_name);
        }

        $issueDate = Carbon::parse(self::text($attributes, 'issue_date'));

        $vendorReference = self::optionalText($attributes, 'vendor_reference');

        if ($type === PurchaseDocumentType::Bill && $vendorReference !== null) {
            $this->refuseDuplicate($contact, $vendorReference, $document);
        }

        return DB::transaction(function () use (
            $type,
            $attributes,
            $lines,
            $document,
            $actor,
            $organization,
            $contact,
            $issueDate,
            $vendorReference,
        ): PurchaseDocument {
            $isNew = $document === null;

            if ($isNew) {
                $document = new PurchaseDocument;

                $document->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->getKey(),
                    'type' => $type,
                    // Claimed at creation: a draft the user can refer to
                    // needs a number, and the sequence is gap-free either way.
                    'number' => $this->numbers->next($type->sequenceKey(), $issueDate),
                    'status' => PurchaseDocumentStatus::Draft,
                    'created_by' => $actor?->getKey(),
                ]);
            }

            /** @var PurchaseDocument $document */
            $document->forceFill([
                'contact_id' => $contact->getKey(),
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $this->dueDate($type, $attributes, $contact, $issueDate),
                'expires_on' => $this->expiryDate($type, $attributes, $issueDate),
                'vendor_reference' => $vendorReference,
                'reference' => self::optionalText($attributes, 'reference'),
                'notes' => self::optionalText($attributes, 'notes'),
                'terms' => self::optionalText($attributes, 'terms'),
                'discount_type' => self::optionalText($attributes, 'discount_type'),
                'discount_value' => self::optionalText($attributes, 'discount_value'),
                'prices_include_tax' => (bool) ($attributes['prices_include_tax'] ?? false),
                // The vendor's currency, falling back to the organisation's.
                'currency' => self::optionalText($attributes, 'currency')
                    ?? $contact->currency
                    ?? $organization->base_currency,
                'exchange_rate' => self::optionalText($attributes, 'exchange_rate') ?? '1',
                'billing_address' => $attributes['billing_address'] ?? $contact->billing_address,
                'shipping_address' => $attributes['shipping_address'] ?? $contact->shipping_address,
            ])->save();

            $this->replaceLines($document, $lines);

            $this->calculator->recalculate($document);

            $this->audit->record(
                action: $isNew ? 'purchases.document_created' : 'purchases.document_updated',
                subject: $document,
                description: sprintf(
                    '%s %s from %s — %s %s',
                    $document->type->label(),
                    $document->number,
                    $contact->display_name,
                    $document->currency,
                    $document->total,
                ),
                new: [
                    'number' => $document->number,
                    'contact' => $contact->display_name,
                    'vendor_reference' => $document->vendor_reference,
                    'total' => $document->total,
                    'lines' => count($lines),
                ],
                actor: $actor,
            );

            return $document->refresh();
        });
    }

    /**
     * Refuse a bill whose vendor reference we already have from this vendor.
     *
     * Not a database constraint, on purpose: vendors do restart their
     * numbering, and a constraint with no override would block a legitimate
     * entry. This refusal names the colliding document, so the person
     * entering it can see whether it really is the same bill.
     */
    private function refuseDuplicate(
        Contact $contact,
        string $reference,
        ?PurchaseDocument $document,
    ): void {
        $existing = PurchaseDocument::query()
            ->where('type', PurchaseDocumentType::Bill->value)
            ->where('contact_id', $contact->getKey())
            ->where('vendor_reference', $reference)
            // Voided bills do not count: the whole point of voiding one is
            // that it is being replaced, usually by a corrected copy of
            // itself carrying the same vendor number.
            ->where('status', '!=', PurchaseDocumentStatus::Void->value)
            ->when($document !== null, fn ($query) => $query->whereKeyNot($document?->getKey()))
            ->first();

        if ($existing !== null) {
            throw PurchaseDocumentRefused::duplicateVendorReference(
                $reference,
                $contact->display_name,
                $existing->number,
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(PurchaseDocument $document, array $lines): void
    {
        $this->refuseUntrackedCostsOnAStockAccount($lines);

        PurchaseDocumentLine::query()
            ->where('purchase_document_id', $document->id)
            ->delete();

        // The fallback for a line with no item and no account of its own.
        $defaultExpense = $this->defaultExpenseAccountId();

        $lineNo = 1;

        foreach ($lines as $input) {
            $item = isset($input['item_id']) && is_string($input['item_id'])
                ? Item::query()->find($input['item_id'])
                : null;

            $line = new PurchaseDocumentLine;

            $line->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $document->organization_id,
                'purchase_document_id' => $document->id,
                'line_no' => $lineNo++,
                'item_id' => $item?->id,
                /*
                 * What the user typed beats what the item says. The item is a
                 * default, and a line already edited must not be reset to it.
                 */
                'description' => self::optionalText($input, 'description')
                    ?? ($item === null ? '' : $item->name),
                'unit' => self::optionalText($input, 'unit') ?? $item?->unit,
                'quantity' => self::optionalText($input, 'quantity') ?? '1',
                'unit_price' => self::optionalText($input, 'unit_price')
                    ?? ($item === null ? null : $item->purchase_price)
                    ?? '0',
                'discount_type' => self::optionalText($input, 'discount_type'),
                'discount_value' => self::optionalText($input, 'discount_value'),
                'tax_id' => self::optionalText($input, 'tax_id') ?? $item?->purchase_tax_id,
                /*
                 * Where the cost lands.
                 *
                 * A STOCK-TRACKED item goes to its inventory account, not to
                 * an expense: §4.6's "5xxx Expense **or** 1300 Inventory".
                 * Buying stock is not a cost yet — it becomes one when the
                 * goods are despatched, which is what §4.10's cost of sales
                 * entry is for. Sending it to an expense would charge the
                 * whole purchase against the month it was bought in and leave
                 * the inventory account permanently understated.
                 *
                 * For a tracked item this is NOT a default the user can
                 * override, and that is the one place this action overrules
                 * what was typed. I10 reconciles an account against the stock
                 * attributed to it; a tracked line posted to an expense
                 * account still puts goods on the shelf, so the stock report
                 * carries value the balance sheet never received and the two
                 * can never be made to agree again. The line is refused
                 * instead — see {@see self::assertTrackedLinesPostToStock()}.
                 *
                 * Otherwise what the user typed wins, and the decision is
                 * frozen on the line at save time: a line is a permanent copy
                 * of what was decided, and a bill saved before an item was
                 * tracked must not change account under it.
                 */
                'debit_account_id' => ($item !== null && $item->is_tracked
                        ? $item->inventory_account_id
                        : null)
                    ?? self::optionalText($input, 'debit_account_id')
                    ?? ($item === null ? null : $item->purchase_account_id)
                    ?? $defaultExpense,
                /*
                 * Claimable unless the line says otherwise. That default is
                 * the common case, and the alternative — defaulting to
                 * blocked — would quietly capitalise recoverable tax into
                 * costs, which nobody would notice until the return was
                 * short.
                 */
                'tax_is_claimable' => ! array_key_exists('tax_is_claimable', $input)
                    || (bool) $input['tax_is_claimable'],
                'project_id' => self::optionalText($input, 'project_id'),
                'warehouse_id' => self::optionalText($input, 'warehouse_id'),
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function text(array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException("A purchase document needs a {$key}.");
        }

        return $value;
    }

    /**
     * An optional string, with a blank treated as absent.
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
     * Where a cost lands when neither the line nor the item says.
     *
     * The first ordinary expense account by code. Deliberately not a system
     * account: an organisation has many expense accounts, and naming one of
     * them "the" expense account would be wrong for nearly every purchase.
     */
    /**
     * Nothing but tracked stock may be costed to an inventory account.
     *
     * The other half of the rule above, and it exists for the same reason.
     * I10 reconciles an inventory account against the stock attributed to it,
     * so that account has to receive exactly what the shelf received and
     * nothing else. A freight line or a consultancy fee coded to 1300 debits
     * the control account without putting anything on any shelf, and from
     * that moment the invariant is out by the amount — not because stock is
     * wrong, but because something that is not stock is being counted as it.
     *
     * Delivery charges genuinely belonging to the goods are capitalised by
     * being part of the item's own line, which is what `capitalisedCost()`
     * exists for.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function refuseUntrackedCostsOnAStockAccount(array $lines): void
    {
        $defaultExpense = $this->defaultExpenseAccountId();

        foreach ($lines as $input) {
            $item = isset($input['item_id']) && is_string($input['item_id'])
                ? Item::query()->find($input['item_id'])
                : null;

            if ($item !== null && $item->is_tracked) {
                continue;
            }

            /*
             * The account the line will actually be SAVED with, resolved the
             * same way {@see self::replaceLines()} resolves it.
             *
             * Checking only what was typed missed the commonest route to the
             * problem: an item with no account on the line falls back to its
             * own `purchase_account_id`, and that field legitimately accepts
             * an asset account. A carriage item pointed at 1300 therefore
             * sailed past a guard that was looking at a null.
             */
            $accountId = self::optionalText($input, 'debit_account_id')
                ?? ($item === null ? null : $item->purchase_account_id)
                ?? $defaultExpense;

            if (! $this->inventoryAccounts->has($accountId)) {
                continue;
            }

            $account = Account::query()->find($accountId);

            throw PurchaseDocumentRefused::notAnInventoryPurchase(
                $account === null ? 'That account' : $account->code.' '.$account->name,
            );
        }
    }

    private function defaultExpenseAccountId(): string
    {
        $account = Account::query()
            ->postable()
            ->where('type', 'expense')
            ->whereNull('system_role')
            ->orderBy('code')
            ->first();

        if ($account !== null) {
            return $account->id;
        }

        // No ordinary expense account at all: an unfinished chart. Fall back
        // to something that exists so the error surfaces at approval with a
        // message about the chart, rather than here with one about a null.
        return Account::query()
            ->where('system_role', SystemAccount::AccountsPayable->value)
            ->sole()
            ->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function dueDate(
        PurchaseDocumentType $type,
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

        // From the vendor's own terms, which is why terms live on a contact.
        return $contact->dueDateFrom($issueDate)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function expiryDate(
        PurchaseDocumentType $type,
        array $attributes,
        Carbon $issueDate,
    ): ?string {
        if ($type !== PurchaseDocumentType::PurchaseOrder) {
            return null;
        }

        $given = self::optionalText($attributes, 'expires_on');

        if ($given !== null) {
            return Carbon::parse($given)->toDateString();
        }

        // Thirty days: long enough to be useful, short enough that a stale
        // order stops being an open commitment.
        return $issueDate->copy()->addDays(30)->toDateString();
    }
}
