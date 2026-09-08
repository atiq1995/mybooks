<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The year-end close.
 *
 * Income and expense accounts are emptied into retained earnings; balance
 * sheet accounts carry forward untouched. That is what makes next year start
 * from zero on the profit and loss while the balance sheet continues.
 *
 * The closing entry is dated on the year's LAST day, inside that year, so the
 * profit and loss for the year it closes still shows the trading it
 * summarises. Every period of the year then moves to `closed` — not `locked`,
 * because a correction may still be needed before filing, and locking is what
 * filing means.
 *
 * The entry is an ordinary journal entry posted through
 * {@see PostJournalEntry}, so it satisfies the same invariants as everything
 * else and can be reversed if the close was premature. Nothing here writes the
 * ledger directly.
 *
 * @see ACCOUNTING_RULES.md §4.14, §7
 */
final readonly class CloseFiscalYear
{
    public function __construct(
        private PostJournalEntry $postJournalEntry,
        private AuditRecorder $audit,
        private TenantContext $tenant,
    ) {}

    /**
     * @return array{entry: JournalEntry|null, net_result: string, accounts_closed: int}
     */
    public function handle(FiscalYear $year, ?User $actor = null): array
    {
        if ($year->isClosed()) {
            throw PostingRefused::yearAlreadyClosed($year->label);
        }

        $retainedEarnings = Account::query()
            ->where('system_role', SystemAccount::RetainedEarnings->value)
            ->first();

        if ($retainedEarnings === null) {
            throw PostingRefused::missingSystemAccount(SystemAccount::RetainedEarnings->value);
        }

        return DB::transaction(function () use ($year, $actor, $retainedEarnings): array {
            $balances = $this->temporaryAccountBalances($year);

            $entry = $balances === []
                ? null
                : $this->postClosingEntry($year, $balances, $retainedEarnings, $actor);

            $netResult = $this->netResult($balances);

            /*
             * Periods close AFTER the entry is posted, in the same
             * transaction. Closing first would have the entry refused by the
             * very periods this close is shutting.
             */
            $this->closePeriods($year, $actor);

            $year->forceFill([
                'status' => PeriodStatus::Closed,
                'closed_at' => Carbon::now(),
                'closed_by' => $actor?->getKey(),
                'closing_entry_id' => $entry?->getKey(),
            ])->save();

            $this->audit->record(
                action: 'accounting.year_closed',
                subject: $year,
                description: sprintf(
                    'Closed financial year %s%s',
                    $year->label,
                    $entry === null
                        ? ' (nothing was posted in it)'
                        : " with {$entry->entry_no}",
                ),
                new: [
                    'label' => $year->label,
                    'net_result' => $netResult,
                    'accounts_closed' => count($balances),
                    'closing_entry' => $entry?->entry_no,
                ],
                actor: $actor,
            );

            return [
                'entry' => $entry,
                'net_result' => $netResult,
                'accounts_closed' => count($balances),
            ];
        });
    }

    /**
     * Every income and expense account with a balance in this year, and what
     * that balance is.
     *
     * Scoped to the year's own dates rather than to all time: closing a year
     * must move only what that year earned and spent, or a second close would
     * move the first year's result again.
     *
     * Balance sheet accounts are absent on purpose — they carry forward.
     *
     * @return array<string, array{account: Account, net: BigDecimal}>
     */
    private function temporaryAccountBalances(FiscalYear $year): array
    {
        $temporary = array_values(array_filter(
            AccountType::cases(),
            static fn (AccountType $type): bool => ! $type->carriesForward(),
        ));

        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('accounts.type', array_map(
                static fn (AccountType $type): string => $type->value,
                $temporary,
            ))
            ->whereDate('journal_entries.entry_date', '>=', $year->starts_on->toDateString())
            ->whereDate('journal_entries.entry_date', '<=', $year->ends_on->toDateString())
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id')
            ->selectRaw('COALESCE(SUM(journal_lines.debit_base), 0) AS debits')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_base), 0) AS credits')
            ->get();

        /** @var array<string, Account> $accounts */
        $accounts = Account::query()
            ->whereKey($rows->pluck('account_id')->all())
            ->get()
            ->keyBy('id')
            ->all();

        $balances = [];

        foreach ($rows as $row) {
            /** @var object{account_id: string, debits: string, credits: string} $row */
            $account = $accounts[$row->account_id] ?? null;

            if ($account === null) {
                continue;
            }

            // Debits minus credits, unsigned by normal balance: the closing
            // entry needs the raw direction, since it posts the opposite of
            // whatever the account holds.
            $net = BigDecimal::of((string) $row->debits)
                ->minus(BigDecimal::of((string) $row->credits));

            // An account that nets to nothing needs no closing line, and a
            // zero-amount line is refused anyway.
            if ($net->isZero()) {
                continue;
            }

            $balances[$account->id] = ['account' => $account, 'net' => $net];
        }

        return $balances;
    }

    /**
     * @param  array<string, array{account: Account, net: BigDecimal}>  $balances
     */
    private function postClosingEntry(
        FiscalYear $year,
        array $balances,
        Account $retainedEarnings,
        ?User $actor,
    ): JournalEntry {
        $lines = [];
        $total = BigDecimal::zero();

        foreach ($balances as $balance) {
            $net = $balance['net'];

            /*
             * Post the opposite of what the account holds, which empties it.
             * A revenue account carries a credit balance (negative net here),
             * so it is debited; an expense account is credited.
             */
            $lines[] = $net->isNegative()
                ? JournalLineDraft::debit(
                    accountId: $balance['account']->id,
                    amount: (string) $net->abs(),
                    memo: "Closing {$year->label}",
                )
                : JournalLineDraft::credit(
                    accountId: $balance['account']->id,
                    amount: (string) $net,
                    memo: "Closing {$year->label}",
                );

            $total = $total->plus($net);
        }

        /*
         * The balancing line.
         *
         * `$total` is debits minus credits across the temporary accounts, so a
         * POSITIVE total means expenses exceeded income — a loss — and
         * retained earnings is DEBITED, reducing equity. A profit credits it.
         *
         * Equivalently: the closing lines above net to −$total by
         * construction, so this line has to net to +$total for the entry to
         * balance. Both readings give the same answer, which is the point of
         * writing it down.
         */
        $lines[] = $total->isPositive()
            ? JournalLineDraft::debit(
                accountId: $retainedEarnings->id,
                amount: (string) $total,
                memo: "Net result for {$year->label}",
            )
            : JournalLineDraft::credit(
                accountId: $retainedEarnings->id,
                amount: (string) $total->abs(),
                memo: "Net result for {$year->label}",
            );

        return $this->postJournalEntry->handle(
            draft: JournalDraft::inBaseCurrency(
                // The year's last day: the entry belongs to the year it
                // closes, not to the one being opened.
                date: Carbon::parse($year->ends_on->toDateString()),
                currency: $this->tenant->organization()->base_currency,
                lines: $lines,
                // The year is the source, so a retried close is refused by the
                // idempotency index rather than doubling the entry.
                source: ['closing', $year->id, 'close'],
                memo: "Year-end close for {$year->label}",
            ),
            actor: $actor,
        );
    }

    /**
     * @param  array<string, array{account: Account, net: BigDecimal}>  $balances
     */
    private function netResult(array $balances): string
    {
        $total = BigDecimal::zero();

        foreach ($balances as $balance) {
            $total = $total->plus($balance['net']);
        }

        // Reported the way a person reads it: positive is a profit. The raw
        // total is debits minus credits, where a profit is negative.
        return (string) $total->negated();
    }

    private function closePeriods(FiscalYear $year, ?User $actor): void
    {
        FiscalPeriod::query()
            ->where('fiscal_year_id', $year->getKey())
            ->where('status', PeriodStatus::Open->value)
            ->update([
                'status' => PeriodStatus::Closed->value,
                'closed_at' => Carbon::now(),
                'closed_by' => $actor?->getKey(),
                'updated_at' => Carbon::now(),
            ]);
    }

    public static function permission(): Permission
    {
        return Permission::AccountingCloseYear;
    }
}
