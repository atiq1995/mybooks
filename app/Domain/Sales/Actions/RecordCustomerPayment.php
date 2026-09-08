<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\DocumentNumberGenerator;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Sales\Data\CustomerPaymentPosting;
use App\Domain\Sales\Enums\SalesDocumentStatus;
use App\Domain\Sales\Exceptions\SalesDocumentRefused;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentAllocation;
use App\Domain\Sales\Models\SalesDocument;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Record money received from a customer, and what it settles.
 *
 * The whole receipt commits at once: the payment, its allocations, each
 * invoice's new balance, the journal entry and the audit row. Half of that
 * would leave a customer's account disagreeing with the ledger, which is the
 * one state this module must never reach.
 *
 * Withholding is taken on the payment, never on the invoice. The customer
 * settles the invoice in FULL and hands part of it to the tax authority on our
 * behalf; the withheld amount is a receivable from that authority. §4.2.
 *
 * Anything not allocated is an advance — a liability, because we owe goods,
 * services or a refund until it is applied. §4.4.
 *
 * @see ACCOUNTING_RULES.md §4.2, §4.3, §4.4, §4.11
 */
final readonly class RecordCustomerPayment
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

            foreach ($documents as $documentId => [$document, $allocated]) {
                $allocatedTotal = $allocatedTotal->plus($allocated);

                /*
                 * The base amount of what this invoice no longer owes, at the
                 * rate the INVOICE was issued at — never a re-derived one.
                 * That is the entire mechanism by which a realised FX gain
                 * becomes visible rather than being absorbed silently.
                 */
                $clearedBase = $clearedBase->plus(
                    $allocated->multipliedBy(BigDecimal::of($document->exchange_rate)),
                );
            }

            if ($allocatedTotal->isGreaterThan($total)) {
                throw SalesDocumentRefused::allocationExceedsPayment(
                    'being recorded',
                    (string) $total->toScale(4),
                );
            }

            $unallocated = $total->minus($allocatedTotal);
            $bankAmount = $total->minus($withheld);

            $payment = new Payment;

            $payment->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organization->getKey(),
                'direction' => 'received',
                'number' => $this->numbers->next('payment_received', $paymentDate),
                'contact_id' => $contact->getKey(),
                'payment_date' => $paymentDate->toDateString(),
                'bank_account_id' => $bankAccountId,
                'method' => $method,
                'reference' => $reference,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'amount' => (string) $total->toScale(4, RoundingMode::HalfUp),
                'withholding_amount' => (string) $withheld->toScale(4, RoundingMode::HalfUp),
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
                draft: (new CustomerPaymentPosting(
                    bankAccountId: $bankAccountId,
                    receivableAccountId: $contact->receivableAccountId(),
                    withholdingAccountId: $this->systemAccountId(SystemAccount::WithholdingTaxReceivable),
                    advancesAccountId: $this->systemAccountId(SystemAccount::CustomerAdvances),
                    fxAccountId: $this->systemAccountId(SystemAccount::FxGainLoss),
                    bankAmount: (string) $bankAmount->toScale(4, RoundingMode::HalfUp),
                    withholdingAmount: (string) $withheld->toScale(4, RoundingMode::HalfUp),
                    receivableCleared: (string) $allocatedTotal->toScale(4, RoundingMode::HalfUp),
                    receivableClearedBase: (string) $clearedBase->toScale(4, RoundingMode::HalfUp),
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
                action: 'sales.payment_received',
                subject: $payment,
                description: sprintf(
                    'Received %s %s from %s%s%s',
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
     * @return array<string, array{0: SalesDocument, 1: BigDecimal}>
     */
    private function resolveDocuments(array $allocations, Contact $contact, string $currency): array
    {
        $resolved = [];

        foreach ($allocations as $documentId => $amount) {
            $allocated = BigDecimal::of($amount);

            // A zero allocation is the UI sending an untouched row, not an
            // instruction. Skipped rather than refused.
            if ($allocated->isNegativeOrZero()) {
                continue;
            }

            $document = SalesDocument::query()->findOrFail($documentId);

            if ($document->contact_id !== $contact->getKey()) {
                throw new \InvalidArgumentException(sprintf(
                    '%s belongs to a different customer, so this payment cannot settle it.',
                    $document->number,
                ));
            }

            /*
             * Same currency only. Settling a dollar invoice with a rupee
             * receipt is a real scenario, but it needs a rate for THAT pair to
             * be meaningful — and guessing one would make the amount cleared
             * ambiguous, which is worse than refusing.
             */
            if ($document->currency !== $currency) {
                throw SalesDocumentRefused::wrongCurrency(
                    $document->number,
                    $document->currency,
                    $currency,
                );
            }

            $due = BigDecimal::of($document->balanceDue());

            if ($allocated->isGreaterThan($due)) {
                throw SalesDocumentRefused::allocationExceedsDocument(
                    $document->number,
                    (string) $due->toScale(4),
                );
            }

            $resolved[(string) $documentId] = [$document, $allocated];
        }

        return $resolved;
    }

    /**
     * Apply one allocation, and move the document's status with it.
     */
    private function allocate(
        Payment $payment,
        SalesDocument $document,
        BigDecimal $allocated,
        string $exchangeRate,
    ): void {
        $atPaymentRate = $allocated->multipliedBy(BigDecimal::of($exchangeRate));
        $atInvoiceRate = $allocated->multipliedBy(BigDecimal::of($document->exchange_rate));

        $allocation = new PaymentAllocation;

        $allocation->forceFill([
            'id' => (string) Str::uuid7(),
            'organization_id' => $payment->organization_id,
            'payment_id' => $payment->id,
            'sales_document_id' => $document->id,
            'amount' => (string) $allocated->toScale(4, RoundingMode::HalfUp),
            'amount_base' => (string) $atInvoiceRate->toScale(4, RoundingMode::HalfUp),
            /*
             * The realised difference on THIS allocation. Stored per
             * allocation because a payment settling two invoices raised at
             * two different rates produces two different gains, and one
             * figure on the payment could not say which was which.
             */
            'fx_gain_loss_base' => (string) $atPaymentRate
                ->minus($atInvoiceRate)
                ->toScale(4, RoundingMode::HalfUp),
        ])->save();

        $paid = BigDecimal::of($document->amount_paid)->plus($allocated);

        $document->forceFill([
            'amount_paid' => (string) $paid->toScale(4, RoundingMode::HalfUp),
        ])->save();

        $document->refresh();

        $document->forceFill([
            'status' => $document->isSettled()
                ? SalesDocumentStatus::Paid
                : SalesDocumentStatus::PartiallyPaid,
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

    public static function permission(): Permission
    {
        return Permission::SalesRecordPayment;
    }
}
