<?php

declare(strict_types=1);

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Actions\ReverseJournalEntry;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\DocumentNumberGenerator;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Banking\Data\BankTransferPosting;
use App\Domain\Banking\Exceptions\BankingRefused;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankTransactionMatch;
use App\Domain\Banking\Models\BankTransfer;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Move money between two of the organisation's own accounts.
 *
 * The one banking document that posts — §8's table says so, and §4.9 gives
 * the entry: debit where it landed, credit where it left, and nothing
 * anywhere near income or expense.
 *
 * Where the two accounts are in different currencies, both amounts are taken
 * as they actually happened rather than one being derived from the other at a
 * rate. Converting money costs something; deriving the far side would make
 * that cost disappear and leave one of the two accounts disagreeing with its
 * statement for ever.
 */
final readonly class RecordBankTransfer
{
    public function __construct(
        private TenantContext $tenant,
        private PostJournalEntry $postJournalEntry,
        private ReverseJournalEntry $reverseJournalEntry,
        private DocumentNumberGenerator $numbers,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        BankAccount $from,
        BankAccount $to,
        string $amount,
        Carbon $transferDate,
        ?string $amountReceived = null,
        string $exchangeRate = '1',
        string $destinationExchangeRate = '1',
        ?string $reference = null,
        ?string $notes = null,
        ?User $actor = null,
        bool $allowClosedPeriod = false,
    ): BankTransfer {
        if ($from->account_id === $to->account_id) {
            throw BankingRefused::transferToSameAccount();
        }

        foreach ([$from, $to] as $account) {
            if (! $account->isUsable()) {
                throw BankingRefused::transferAccountUnusable($account->name);
            }
        }

        $sent = BigDecimal::of($amount);

        if ($sent->isNegativeOrZero()) {
            throw new \InvalidArgumentException('A transfer must be greater than zero.');
        }

        /*
         * Same currency on both sides and no amount given: what left is what
         * arrived. Different currencies with no amount given is an error we
         * refuse to guess at, because guessing means inventing a rate.
         */
        if ($amountReceived === null) {
            if ($from->currency !== $to->currency) {
                throw new \InvalidArgumentException(
                    'A transfer between two currencies has to say how much arrived: '.
                    'the rate the bank actually used is not something this can derive.'
                );
            }

            $amountReceived = $amount;
        }

        $received = BigDecimal::of($amountReceived);

        if ($received->isNegativeOrZero()) {
            throw new \InvalidArgumentException('The amount received must be greater than zero.');
        }

        $organization = $this->tenant->organization();

        return DB::transaction(function () use (
            $from,
            $to,
            $sent,
            $received,
            $transferDate,
            $exchangeRate,
            $destinationExchangeRate,
            $reference,
            $notes,
            $actor,
            $organization,
            $allowClosedPeriod,
        ): BankTransfer {
            $transfer = new BankTransfer;

            $transfer->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organization->getKey(),
                'number' => $this->numbers->next('bank_transfer', $transferDate),
                'transfer_date' => $transferDate->toDateString(),
                'from_account_id' => $from->account_id,
                'to_account_id' => $to->account_id,
                'currency' => $from->currency,
                'amount' => (string) $sent->toScale(4, RoundingMode::HalfUp),
                'exchange_rate' => $exchangeRate,
                'destination_currency' => $to->currency,
                'amount_received' => (string) $received->toScale(4, RoundingMode::HalfUp),
                'destination_exchange_rate' => $destinationExchangeRate,
                'reference' => $reference,
                'notes' => $notes,
                'created_by' => $actor?->getKey(),
            ])->save();

            $entry = $this->postJournalEntry->handle(
                draft: (new BankTransferPosting(
                    fromAccountId: $from->account_id,
                    toAccountId: $to->account_id,
                    fxAccountId: $this->systemAccountId(SystemAccount::FxGainLoss),
                    amount: $transfer->amount,
                    currency: $transfer->currency,
                    exchangeRate: $exchangeRate,
                    amountReceived: $transfer->amount_received,
                    destinationCurrency: $transfer->destination_currency,
                    destinationExchangeRate: $destinationExchangeRate,
                    baseCurrency: $organization->base_currency,
                    date: $transferDate,
                    transferId: $transfer->id,
                    transferNumber: $transfer->number,
                    reference: $reference,
                ))->toDraft(),
                actor: $actor,
                allowClosedPeriod: $allowClosedPeriod,
            );

            $transfer->forceFill(['journal_entry_id' => $entry->getKey()])->save();

            $this->audit->record(
                action: 'banking.transfer_recorded',
                subject: $transfer,
                description: sprintf(
                    'Transferred %s %s from %s to %s%s',
                    $transfer->currency,
                    $transfer->amount,
                    $from->name,
                    $to->name,
                    $transfer->isCrossCurrency()
                        ? " (arrived as {$transfer->destination_currency} {$transfer->amount_received})"
                        : '',
                ),
                new: [
                    'number' => $transfer->number,
                    'amount' => $transfer->amount,
                    'amount_received' => $transfer->amount_received,
                    'journal_entry' => $entry->entry_no,
                ],
                actor: $actor,
            );

            return $transfer->refresh();
        });
    }

    /**
     * Void a transfer by reversing it.
     *
     * Refused once either side has been reconciled: the bank statement says
     * the money moved, a completed reconciliation says we agreed, and undoing
     * it would leave a period that reconciled to zero no longer doing so. A
     * correcting transfer in a later period is the route, and it keeps both
     * periods adding up.
     */
    public function void(
        BankTransfer $transfer,
        ?User $actor = null,
        ?Carbon $date = null,
        ?string $reason = null,
    ): BankTransfer {
        if ($transfer->isVoided()) {
            throw BankingRefused::transferAlreadyVoided($transfer->number);
        }

        $entry = $transfer->journalEntry;

        if ($entry === null) {
            throw new \LogicException(
                "Transfer {$transfer->number} has no journal entry, which should be impossible: ".
                'the entry is posted in the same transaction that creates the transfer.'
            );
        }

        $reconciled = BankTransactionMatch::query()
            ->whereIn(
                'journal_line_id',
                DB::table('journal_lines')
                    ->where('journal_entry_id', $entry->getKey())
                    ->select('id'),
            )
            ->whereNotNull('reconciliation_id')
            ->exists();

        if ($reconciled) {
            throw BankingRefused::transferReconciled($transfer->number);
        }

        return DB::transaction(function () use ($transfer, $entry, $actor, $date, $reason): BankTransfer {
            $reversal = $this->reverseJournalEntry->handle(
                entry: $entry,
                actor: $actor,
                date: $date,
                reason: $reason ?? "Transfer {$transfer->number} voided",
            );

            $transfer->forceFill([
                'void_journal_entry_id' => $reversal->getKey(),
                'voided_at' => Carbon::now(),
                'voided_by' => $actor?->getKey(),
            ])->save();

            $this->audit->record(
                action: 'banking.transfer_voided',
                subject: $transfer,
                description: sprintf(
                    'Voided transfer %s by reversal %s%s',
                    $transfer->number,
                    $reversal->entry_no,
                    $reason === null ? '' : " — {$reason}",
                ),
                new: ['reversal' => $reversal->entry_no],
                actor: $actor,
            );

            return $transfer->refresh();
        });
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
