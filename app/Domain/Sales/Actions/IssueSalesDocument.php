<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Sales\Data\CreditNotePosting;
use App\Domain\Sales\Data\InvoicePosting;
use App\Domain\Sales\Enums\SalesDocumentStatus;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Domain\Sales\Exceptions\SalesDocumentRefused;
use App\Domain\Sales\Models\SalesDocument;
use App\Domain\Sales\Models\SalesDocumentLine;
use App\Domain\Sales\Models\SalesDocumentLineTax;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Issue a sales document.
 *
 * For an invoice or a credit note this posts to the ledger; for an estimate or
 * a sales order it does not, because those are commitments and §6 says so.
 * One action rather than four because "issue" means the same thing in every
 * case — the document stops being a draft and becomes a record — and only its
 * accounting effect differs.
 *
 * The posting rule itself lives in a pure class ({@see InvoicePosting},
 * {@see CreditNotePosting}) so it can be asserted line by line without a
 * database. This action's job is to gather the figures, hand them over, and
 * commit the document, the entry and the audit row together.
 *
 * Nothing here writes the ledger directly: `PostJournalEntry` is still the
 * only code permitted to, and it applies the balance, period and account
 * checks to what this produces exactly as it would to a hand-written journal.
 *
 * @see ACCOUNTING_RULES.md §4.1, §4.5, §6
 */
final readonly class IssueSalesDocument
{
    public function __construct(
        private TenantContext $tenant,
        private PostJournalEntry $postJournalEntry,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        SalesDocument $document,
        ?User $actor = null,
        bool $allowClosedPeriod = false,
    ): SalesDocument {
        if ($document->status->isIssued()) {
            throw SalesDocumentRefused::alreadyIssued($document->type->label(), $document->number);
        }

        if ($document->lines()->count() === 0) {
            throw SalesDocumentRefused::hasNoLines($document->type->label(), $document->number);
        }

        // A document totalling nothing has no accounting effect, and the
        // ledger refuses a zero-value entry outright.
        if ($document->type->posts() && BigDecimal::of($document->total)->isZero()) {
            throw SalesDocumentRefused::nothingToIssue(
                $document->type->label(),
                $document->number,
            );
        }

        return DB::transaction(function () use ($document, $actor, $allowClosedPeriod): SalesDocument {
            $entryId = null;

            if ($document->type->posts()) {
                $entry = $this->postJournalEntry->handle(
                    draft: $document->type === SalesDocumentType::CreditNote
                        ? $this->creditNoteDraft($document)
                        : $this->invoiceDraft($document),
                    actor: $actor,
                    allowClosedPeriod: $allowClosedPeriod,
                );

                $entryId = $entry->getKey();
            }

            $document->forceFill([
                'status' => $this->statusOnIssue($document->type),
                'journal_entry_id' => $entryId,
                'issued_at' => Carbon::now(),
                'issued_by' => $actor?->getKey(),
            ])->save();

            if ($document->type === SalesDocumentType::CreditNote) {
                $this->applyCreditToInvoice($document);
            }

            $this->audit->record(
                action: 'sales.document_issued',
                subject: $document,
                description: sprintf(
                    'Issued %s %s — %s %s%s',
                    $document->type->label(),
                    $document->number,
                    $document->currency,
                    $document->total,
                    $entryId === null ? ' (no accounting effect)' : '',
                ),
                new: [
                    'number' => $document->number,
                    'total' => $document->total,
                    'total_base' => $document->total_base,
                    'journal_entry' => $entryId,
                ],
                actor: $actor,
            );

            return $document->refresh();
        });
    }

    /**
     * The §4.1 figures.
     *
     * Revenue gross per line, the discount attributed to that line, and tax
     * summed per component across the whole document — which is the shape the
     * posting rule needs and the shape the tax return is filed in.
     */
    private function invoiceDraft(SalesDocument $document): JournalDraft
    {
        $organization = $this->tenant->organization();

        $revenueLines = array_values(
            $document->lines()->get()
                ->map(fn (SalesDocumentLine $line): array => [
                    'account_id' => $line->revenue_account_id,
                    'gross' => $line->gross,
                    // Both discounts belong to contra-revenue: a
                    // document-level discount is no less a discount for
                    // having been entered once rather than per line.
                    'discount' => (string) BigDecimal::of($line->discount_amount)
                        ->plus(BigDecimal::of($line->document_discount_amount))
                        ->toScale(4),
                ])
                ->all(),
        );

        return new InvoicePosting(
            receivableAccountId: $this->receivableAccountId($document),
            discountAccountId: $this->systemAccountId(SystemAccount::TradeDiscounts),
            revenueLines: $revenueLines,
            taxes: $this->taxesByAccount($document),
            currency: $document->currency,
            baseCurrency: $organization->base_currency,
            exchangeRate: $document->exchange_rate,
            date: Carbon::parse($document->issue_date->toDateString()),
            documentId: $document->id,
            documentNumber: $document->number,
            sourceType: $document->type->ledgerSource(),
            contactId: $document->contact_id,
        )->toDraft();
    }

    /**
     * The §4.5 figures.
     */
    private function creditNoteDraft(SalesDocument $document): JournalDraft
    {
        $organization = $this->tenant->organization();

        $netLines = array_values(
            $document->lines()->get()
                ->map(fn (SalesDocumentLine $line): array => ['amount' => $line->taxable])
                ->all(),
        );

        return new CreditNotePosting(
            receivableAccountId: $this->receivableAccountId($document),
            salesReturnsAccountId: $this->systemAccountId(SystemAccount::SalesReturns),
            netLines: $netLines,
            taxes: $this->taxesByAccount($document),
            currency: $document->currency,
            baseCurrency: $organization->base_currency,
            exchangeRate: $document->exchange_rate,
            date: Carbon::parse($document->issue_date->toDateString()),
            documentId: $document->id,
            documentNumber: $document->number,
            contactId: $document->contact_id,
        )->toDraft();
    }

    /**
     * Tax summed per liability account.
     *
     * Per ACCOUNT rather than per component, because two components sharing an
     * account produce one journal line — while the per-component breakdown
     * that the return needs stays on `sales_document_line_taxes`, where it
     * belongs. The journal is a summary; the document is the detail.
     *
     * @return list<array{account_id: string, amount: string}>
     */
    private function taxesByAccount(SalesDocument $document): array
    {
        $totals = [];

        foreach ($document->lineTaxes()->get() as $lineTax) {
            /** @var SalesDocumentLineTax $lineTax */
            $accountId = $lineTax->account_id ?? $this->systemAccountId(SystemAccount::GstOutput);

            $totals[$accountId] = isset($totals[$accountId])
                ? $totals[$accountId]->plus(BigDecimal::of($lineTax->tax_amount))
                : BigDecimal::of($lineTax->tax_amount);
        }

        $taxes = [];

        foreach ($totals as $accountId => $amount) {
            $taxes[] = [
                'account_id' => (string) $accountId,
                'amount' => (string) $amount->toScale(4),
            ];
        }

        return $taxes;
    }

    /**
     * Record the credit against the invoice it credits.
     *
     * `amount_credited` rather than `amount_paid`: a credit is not money, and
     * conflating them would make "cash received this month" wrong. Both
     * reduce the balance due, and the aging report reads the difference.
     */
    private function applyCreditToInvoice(SalesDocument $creditNote): void
    {
        if ($creditNote->credits_document_id === null) {
            return;
        }

        $invoice = SalesDocument::query()->find($creditNote->credits_document_id);

        if ($invoice === null) {
            return;
        }

        $credited = BigDecimal::of($invoice->amount_credited)
            ->plus(BigDecimal::of($creditNote->total));

        $invoice->forceFill([
            'amount_credited' => (string) $credited->toScale(4),
        ])->save();

        // Fully credited is settled, as far as the customer's balance goes.
        $invoice->refresh();

        if ($invoice->isSettled()) {
            $invoice->forceFill(['status' => SalesDocumentStatus::Paid])->save();
        }
    }

    /**
     * What issuing means for each type.
     *
     * An invoice becomes `sent` rather than `open`: §6 lists both, and `sent`
     * is the one that says a human did it. `open` is reached by a recurring
     * invoice generated without anybody pressing anything.
     */
    private function statusOnIssue(SalesDocumentType $type): SalesDocumentStatus
    {
        return SalesDocumentStatus::Sent;
    }

    /**
     * The receivable control account for this document's customer.
     *
     * Loaded rather than reached through the relation so the type is known,
     * and so a document whose contact has been deleted fails with something
     * readable rather than a null-property error mid-posting.
     */
    private function receivableAccountId(SalesDocument $document): string
    {
        return Contact::query()->findOrFail($document->contact_id)->receivableAccountId();
    }

    private function systemAccountId(SystemAccount $role): string
    {
        $id = Account::query()->where('system_role', $role->value)->value('id');

        if (! is_string($id)) {
            throw PostingRefused::missingSystemAccount($role->value);
        }

        return $id;
    }

    public static function permission(): Permission
    {
        return Permission::SalesSend;
    }
}
