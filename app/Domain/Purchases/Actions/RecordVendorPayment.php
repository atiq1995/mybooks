<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Actions;

use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\DocumentNumberGenerator;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Purchases\Data\VendorPaymentPosting;
use App\Domain\Purchases\Enums\PurchaseDocumentStatus;
use App\Domain\Purchases\Exceptions\PurchaseDocumentRefused;
use App\Domain\Purchases\Models\PurchaseDocument;
use App\Domain\Purchases\Models\PurchasePaymentAllocation;
use App\Domain\Sales\Models\Payment;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Record money paid to a vendor, and what it settles.
 *
 * The whole payment commits at once: the payment, its allocations, each
 * bill's new balance, the journal entry and the audit row. Half of that would
 * leave a vendor's account disagreeing with the ledger.
 *
 * Withholding is taken on the PAYMENT, never on the bill — §4.7. We deduct
 * the tax and remit the rest; the vendor's account is settled in full and the
 * withheld amount becomes a liability to the tax authority until we hand it
 * over. The tempting alternative, settling the bill by only what we paid,
 * would leave it permanently part-paid and the tax we are holding invisible.
 *
 * Anything not allocated is an advance with the vendor — an asset, because
 * they owe us goods, services or the money back.
 *
 * @see ACCOUNTING_RULES.md §4.7, §4.11
 */
final readonly class RecordVendorPayment
{
    public function __construct(
        private TenantContext $tenant,
        private PostJournalEntry $postJournalEntry,
        private DocumentNumberGenerator $numbers,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, string>  $allocations  document id => amount, in
     *                                              the payment's currency
     */
    public function handle(
        Contact $contact,
        string $bankAccountId,
        string $amount,
        Carbon $paymentDate,
        array $allocations = [],
        string $withholdingAmount = '0',
        ?string $withholdingTaxId = null,
        ?string $currency = null,
        string $exchangeRate = '1',
        string $method = 'bank_transfer',
        ?string $reference = null,
        ?string $notes = null,
        ?User $actor = null,
        bool $allowClosedPeriod = false,
    ): Payment {
        $organization = $this->tenant->organization();
        $currency ??= $organization->base_currency;

        $total = BigDecimal::of($amount);
        $withheld = BigDecimal::of($withholdingAmount);

        if ($total->isNegativeOrZero()) {
            throw new \InvalidArgumentException('A payment must be greater than zero.');
        }

        if ($withheld->isGreaterThan($total)) {
            throw new \InvalidArgumentException(
                'Withholding cannot exceed the payment: it is a share of the amount settled, '.
                'not an addition to it.'
            );
        }

        return DB::transaction(function () use (
            $contact,
            $bankAccountId,
            $total,
            $withheld,
            $withholdingTaxId,
            $paymentDate,
            $allocations,
            $currency,
            $exchangeRate,
            $method,
            $reference,
            $notes,
            $actor,
            $organization,
            $allowClosedPeriod,
        ): Payment {
            $documents = $this->resolveDocuments($allocations, $contact, $currency);

            $allocatedTotal = BigDecimal::zero();
            $clearedBase = BigDecimal::zero();

            foreach ($documents as [$document, $allocated]) {
                $allocatedTotal = $allocatedTotal->plus($allocated);

                /*
                 * What this bill no longer owes, at the rate the BILL was
                 * booked at — never a re-derived one. That is the whole
                 * mechanism by which a realised FX difference becomes visible
                 * rather than being absorbed silently.
                 */
                $clearedBase = $clearedBase->plus(
                    $allocated->multipliedBy(BigDecimal::of($document->exchange_rate)),
                );
            }

            if ($allocatedTotal->isGreaterThan($total)) {
                throw PurchaseDocumentRefused::allocationExceedsPayment(
                    'being recorded',
                    (string) $total->toScale(4),
                );
            }

            $unallocated = $total->minus($allocatedTotal);

            // What actually left the bank: the amount settled, less what we
            // withheld and still hold.
            $bankAmount = $total->minus($withheld);

            $payment = new Payment;

            $payment->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organization->getKey(),
                'direction' => 'made',
                'number' => $this->numbers->next('payment_made', $paymentDate),
                'contact_id' => $contact->getKey(),
                'payment_date' => $paymentDate->toDateString(),
                'bank_account_id' => $bankAccountId,
                'method' => $method,
                'reference' => $reference,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'amount' => (string) $total->toScale(4, RoundingMode::HalfUp),
                'withholding_amount' => (string) $withheld->toScale(4, RoundingMode::HalfUp),
                /*
                 * `amount_received` is the column's name from the sales side,
                 * and on this side it means what left rather than what
                 * arrived. The identity it maintains — amount less
                 * withholding — is the same either way, and it is enforced by
                 * a check constraint, so the meaning is "what actually moved
                 * at the bank" in both directions.
                 */
                'amount_received' => (string) $bankAmount->toScale(4, RoundingMode::HalfUp),
                'amount_base' => (string) $total
                    ->multipliedBy(BigDecimal::of($exchangeRate))
                    ->toScale(4, RoundingMode::HalfUp),
                'withholding_amount_base' => (string) $withheld
                    ->multipliedBy(BigDecimal::of($exchangeRate))
                    ->toScale(4, RoundingMode::HalfUp),
                'withholding_tax_id' => $withholdingTaxId,
                'allocated_amount' => (string) $allocatedTotal->toScale(4, RoundingMode::HalfUp),
                'status' => 'completed',
                'notes' => $notes,
                'created_by' => $actor?->getKey(),
            ])->save();

            foreach ($documents as [$document, $allocated]) {
                $this->allocate($payment, $document, $allocated, $exchangeRate);
            }

            $entry = $this->postJournalEntry->handle(
                draft: (new VendorPaymentPosting(
                    bankAccountId: $bankAccountId,
                    payableAccountId: $contact->payableAccountId(),
                    withholdingAccountId: $this->systemAccountId(SystemAccount::WithholdingTaxPayable),
                    advancesAccountId: $this->systemAccountId(SystemAccount::VendorAdvances),
                    fxAccountId: $this->systemAccountId(SystemAccount::FxGainLoss),
                    bankAmount: (string) $bankAmount->toScale(4, RoundingMode::HalfUp),
                    withholdingAmount: (string) $withheld->toScale(4, RoundingMode::HalfUp),
                    payableCleared: (string) $allocatedTotal->toScale(4, RoundingMode::HalfUp),
                    payableClearedBase: (string) $clearedBase->toScale(4, RoundingMode::HalfUp),
                    unallocatedAmount: (string) $unallocated->toScale(4, RoundingMode::HalfUp),
                    currency: $currency,
                    baseCurrency: $organization->base_currency,
                    exchangeRate: $exchangeRate,
                    date: $paymentDate,
                    paymentId: $payment->id,
                    paymentNumber: $payment->number,
                    contactId: $contact->id,
                ))->toDraft(),
                actor: $actor,
                allowClosedPeriod: $allowClosedPeriod,
            );

            $payment->forceFill(['journal_entry_id' => $entry->getKey()])->save();

            $this->audit->record(
                action: 'purchases.payment_made',
                subject: $payment,
                description: sprintf(
                    'Paid %s %s to %s%s%s',
                    $currency,
                    $payment->amount,
                    $contact->display_name,
                    $withheld->isPositive() ? " ({$payment->withholding_amount} withheld)" : '',
                    $unallocated->isPositive()
                        ? ' — '.$unallocated->toScale(4).' held as an advance'
                        : '',
                ),
                new: [
                    'number' => $payment->number,
                    'amount' => $payment->amount,
                    'withholding' => $payment->withholding_amount,
                    'allocated' => $payment->allocated_amount,
                    'documents' => count($documents),
                    'journal_entry' => $entry->entry_no,
                ],
                amount: null,
                actor: $actor,
            );

            return $payment->refresh();
        });
    }

    /**
     * Resolve and validate what this payment claims to settle.
     *
     * @param  array<string, string>  $allocations
     * @return array<string, array{0: PurchaseDocument, 1: BigDecimal}>
     */
    private function resolveDocuments(array $allocations, Contact $contact, string $currency): array
    {
        $resolved = [];

        foreach ($allocations as $documentId => $amount) {
            $allocated = BigDecimal::of($amount);

            // A zero allocation is an untouched row in the form, not an
            // instruction. Skipped rather than refused.
            if ($allocated->isNegativeOrZero()) {
                continue;
            }

            $document = PurchaseDocument::query()->findOrFail($documentId);

            if ($document->contact_id !== $contact->getKey()) {
                throw new \InvalidArgumentException(sprintf(
                    '%s belongs to a different vendor, so this payment cannot settle it.',
                    $document->number,
                ));
            }

            /*
             * Same currency only. Paying a dollar bill out of a rupee account
             * is a real scenario, but it needs a rate for THAT pair to mean
             * anything, and guessing one would make the amount cleared
             * ambiguous — worse than refusing.
             */
            if ($document->currency !== $currency) {
                throw PurchaseDocumentRefused::wrongCurrency(
                    $document->number,
                    $document->currency,
                    $currency,
                );
            }

            $due = BigDecimal::of($document->balanceDue());

            if ($allocated->isGreaterThan($due)) {
                throw PurchaseDocumentRefused::allocationExceedsDocument(
                    $document->number,
                    (string) $due->toScale(4),
                );
            }

            $resolved[(string) $documentId] = [$document, $allocated];
        }

        return $resolved;
    }

    /**
     * Apply one allocation, and move the bill's status with it.
     */
    private function allocate(
        Payment $payment,
        PurchaseDocument $document,
        BigDecimal $allocated,
        string $exchangeRate,
    ): void {
        $atPaymentRate = $allocated->multipliedBy(BigDecimal::of($exchangeRate));
        $atBillRate = $allocated->multipliedBy(BigDecimal::of($document->exchange_rate));

        $allocation = new PurchasePaymentAllocation;

        $allocation->forceFill([
            'id' => (string) Str::uuid7(),
            'organization_id' => $payment->organization_id,
            'payment_id' => $payment->id,
            'purchase_document_id' => $document->id,
            'amount' => (string) $allocated->toScale(4, RoundingMode::HalfUp),
            'amount_base' => (string) $atBillRate->toScale(4, RoundingMode::HalfUp),
            /*
             * The realised difference on THIS allocation, signed from our
             * side: positive is a gain. The payable was carried at the bill's
             * rate; we paid at today's, so paying less than it was carried at
             * is a gain to us.
             */
            'fx_gain_loss_base' => (string) $atBillRate
                ->minus($atPaymentRate)
                ->toScale(4, RoundingMode::HalfUp),
        ])->save();

        $paid = BigDecimal::of($document->amount_paid)->plus($allocated);

        $document->forceFill([
            'amount_paid' => (string) $paid->toScale(4, RoundingMode::HalfUp),
        ])->save();

        $document->refresh();

        $document->forceFill([
            'status' => $document->isSettled()
                ? PurchaseDocumentStatus::Paid
                : PurchaseDocumentStatus::PartiallyPaid,
        ])->save();
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
