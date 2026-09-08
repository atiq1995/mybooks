<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Actions;

use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Purchases\Data\BillPosting;
use App\Domain\Purchases\Data\VendorCreditPosting;
use App\Domain\Purchases\Enums\PurchaseDocumentStatus;
use App\Domain\Purchases\Enums\PurchaseDocumentType;
use App\Domain\Purchases\Exceptions\PurchaseDocumentRefused;
use App\Domain\Purchases\Models\PurchaseDocument;
use App\Domain\Purchases\Models\PurchaseDocumentLine;
use App\Domain\Purchases\Models\PurchaseDocumentLineTax;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Approve a purchase document — which for a bill or a vendor credit posts it,
 * and for a purchase order simply sends it.
 *
 * §6 puts the posting moment at APPROVAL on this side, not at entry, and that
 * is the substantive difference from the sales module. An invoice is issued
 * by the person who wrote it, so writing it and issuing it are one decision.
 * A bill arrives from outside: somebody enters what the vendor sent, and
 * somebody agrees we owe it. Until that second step the liability is not ours
 * to recognise, and a database constraint ties `approved_at` to
 * `journal_entry_id` so the two facts cannot come apart.
 *
 * The posting rules live in pure classes ({@see BillPosting},
 * {@see VendorCreditPosting}) so they can be asserted line by line without a
 * database. This action gathers figures, hands them over, and commits the
 * document, the entry and the audit row together.
 *
 * Nothing here writes the ledger directly: `PostJournalEntry` remains the
 * only code permitted to.
 *
 * @see ACCOUNTING_RULES.md §4.6, §6
 */
final readonly class ApprovePurchaseDocument
{
    public function __construct(
        private TenantContext $tenant,
        private PostJournalEntry $postJournalEntry,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        PurchaseDocument $document,
        ?User $actor = null,
        bool $allowClosedPeriod = false,
    ): PurchaseDocument {
        if ($document->status->isIssued()) {
            throw PurchaseDocumentRefused::alreadyIssued(
                $document->type->label(),
                $document->number,
            );
        }

        if ($document->lines()->count() === 0) {
            throw PurchaseDocumentRefused::hasNoLines(
                $document->type->label(),
                $document->number,
            );
        }

        if ($document->type->posts() && BigDecimal::of($document->total)->isZero()) {
            throw PurchaseDocumentRefused::nothingToIssue(
                $document->type->label(),
                $document->number,
            );
        }

        return DB::transaction(function () use (
            $document,
            $actor,
            $allowClosedPeriod,
        ): PurchaseDocument {
            $entryId = null;

            if ($document->type->posts()) {
                $entry = $this->postJournalEntry->handle(
                    draft: $document->type === PurchaseDocumentType::VendorCredit
                        ? $this->vendorCreditDraft($document)
                        : $this->billDraft($document),
                    actor: $actor,
                    allowClosedPeriod: $allowClosedPeriod,
                );

                $entryId = $entry->getKey();
            }

            $document->forceFill([
                'status' => $this->statusOnApproval($document->type),
                'journal_entry_id' => $entryId,
                /*
                 * Only where the type posts. The constraint requires
                 * `approved_at` and `journal_entry_id` to agree on a bill,
                 * and a purchase order has no approval to record — it was
                 * sent, which is what its status says.
                 */
                'approved_at' => $document->type->posts() ? Carbon::now() : null,
                'approved_by' => $document->type->posts() ? $actor?->getKey() : null,
            ])->save();

            if ($document->type === PurchaseDocumentType::VendorCredit) {
                $this->applyCreditToBill($document);
            }

            $this->audit->record(
                action: 'purchases.document_approved',
                subject: $document,
                description: sprintf(
                    '%s %s %s — %s %s%s',
                    $document->type->issueVerb() === 'Send' ? 'Sent' : 'Approved',
                    $document->type->label(),
                    $document->number,
                    $document->currency,
                    $document->total,
                    $entryId === null ? ' (no accounting effect)' : '',
                ),
                new: [
                    'number' => $document->number,
                    'vendor_reference' => $document->vendor_reference,
                    'total' => $document->total,
                    'total_base' => $document->total_base,
                    'tax_claimable' => $document->tax_claimable_total,
                    'journal_entry' => $entryId,
                ],
                actor: $actor,
            );

            return $document->refresh();
        });
    }

    /**
     * The §4.6 figures.
     *
     * The cost per line is the taxable amount plus any tax that cannot be
     * reclaimed — {@see PurchaseDocumentLine::capitalisedCost()} — and the
     * claimable tax is summed per account across the claimable lines only.
     * Those two together are exactly the total payable, which is what makes
     * the entry balance without the payable being passed in.
     */
    private function billDraft(PurchaseDocument $document): JournalDraft
    {
        $organization = $this->tenant->organization();

        $costLines = array_values(
            $document->lines()->get()
                ->map(fn (PurchaseDocumentLine $line): array => [
                    'account_id' => $line->debit_account_id,
                    'amount' => $line->capitalisedCost(),
                ])
                ->all(),
        );

        return new BillPosting(
            payableAccountId: $this->payableAccountId($document),
            costLines: $costLines,
            claimableTaxes: $this->claimableTaxesByAccount($document),
            currency: $document->currency,
            baseCurrency: $organization->base_currency,
            exchangeRate: $document->exchange_rate,
            date: Carbon::parse($document->issue_date->toDateString()),
            documentId: $document->id,
            documentNumber: $document->number,
            sourceType: $document->type->ledgerSource(),
            contactId: $document->contact_id,
            vendorReference: $document->vendor_reference,
        )->toDraft();
    }

    /**
     * A vendor credit: §4.6 run backwards.
     */
    private function vendorCreditDraft(PurchaseDocument $document): JournalDraft
    {
        $organization = $this->tenant->organization();

        $costLines = array_values(
            $document->lines()->get()
                ->map(fn (PurchaseDocumentLine $line): array => [
                    'account_id' => $line->debit_account_id,
                    'amount' => $line->capitalisedCost(),
                ])
                ->all(),
        );

        return new VendorCreditPosting(
            payableAccountId: $this->payableAccountId($document),
            costLines: $costLines,
            claimableTaxes: $this->claimableTaxesByAccount($document),
            currency: $document->currency,
            baseCurrency: $organization->base_currency,
            exchangeRate: $document->exchange_rate,
            date: Carbon::parse($document->issue_date->toDateString()),
            documentId: $document->id,
            documentNumber: $document->number,
            contactId: $document->contact_id,
            vendorReference: $document->vendor_reference,
        )->toDraft();
    }

    /**
     * Claimable input tax, summed per receivable account.
     *
     * The non-claimable rows are skipped here and capitalised into the cost
     * instead — §4.6, and the single most consequential line in this file.
     * Including them would put tax we cannot recover on the balance sheet as
     * an asset.
     *
     * Per ACCOUNT rather than per component, because two components sharing
     * an account produce one journal line; the per-component detail the
     * return needs stays on `purchase_document_line_taxes`.
     *
     * @return list<array{account_id: string, amount: string}>
     */
    private function claimableTaxesByAccount(PurchaseDocument $document): array
    {
        $totals = [];

        foreach ($document->lineTaxes()->get() as $lineTax) {
            /** @var PurchaseDocumentLineTax $lineTax */
            if (! $lineTax->is_claimable) {
                continue;
            }

            $accountId = $lineTax->account_id ?? $this->systemAccountId(SystemAccount::GstInput);

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
     * Record the credit against the bill it credits.
     *
     * `amount_credited` rather than `amount_paid`: a credit is not money, and
     * conflating them would make "cash paid this month" wrong. Both reduce
     * the balance due, and the payables ageing reads the difference.
     */
    private function applyCreditToBill(PurchaseDocument $credit): void
    {
        if ($credit->credits_document_id === null) {
            return;
        }

        $bill = PurchaseDocument::query()->find($credit->credits_document_id);

        if ($bill === null) {
            return;
        }

        $credited = BigDecimal::of($bill->amount_credited)
            ->plus(BigDecimal::of($credit->total));

        $bill->forceFill([
            'amount_credited' => (string) $credited->toScale(4),
        ])->save();

        $bill->refresh();

        if ($bill->isSettled()) {
            $bill->forceFill(['status' => PurchaseDocumentStatus::Paid])->save();
        }
    }

    /**
     * What approval means for each type.
     *
     * A bill and a vendor credit become `open`: approved, and owed. A
     * purchase order becomes `sent`, which is all that happened to it.
     */
    private function statusOnApproval(PurchaseDocumentType $type): PurchaseDocumentStatus
    {
        return $type === PurchaseDocumentType::PurchaseOrder
            ? PurchaseDocumentStatus::Sent
            : PurchaseDocumentStatus::Open;
    }

    /**
     * The payable control account for this document's vendor.
     *
     * Loaded rather than reached through the relation, so a document whose
     * contact has gone fails with something readable rather than a
     * null-property error mid-posting.
     */
    private function payableAccountId(PurchaseDocument $document): string
    {
        return Contact::query()->findOrFail($document->contact_id)->payableAccountId();
    }

    private function systemAccountId(SystemAccount $role): string
    {
        $id = Account::query()->where('system_role', $role->value)->value('id');

        if (! is_string($id)) {
            throw PostingRefused::missingSystemAccount($role->value);
        }

        return $id;
    }
}
