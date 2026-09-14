<?php

declare(strict_types=1);

namespace App\Domain\Banking\Services;

use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Banking\Enums\StatementLineStatus;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankReconciliation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The four figures a reconciliation turns on, and the two that explain them.
 *
 *   opening     what the bank said at the start — the previous
 *               reconciliation's closing balance, so the chain is unbroken
 *   cleared     opening, plus every statement line in the period that a
 *               person has matched to an entry of ours
 *   closing     what the bank says at the end, typed in from the statement
 *   difference  closing − cleared, and it must be zero to complete
 *
 * Alongside those, two figures that say WHERE a difference is coming from:
 * the ledger balance at the period end, and the entries of ours up to that
 * date that no statement line has cleared — unpresented cheques, in the
 * traditional phrase. When every earlier period reconciled, the identity
 *
 *     cleared = ledger balance − unpresented
 *
 * holds exactly, which is what makes this a reconciliation of the BOOKS
 * against the bank rather than of a file against itself.
 *
 * Nothing here writes. It is arithmetic over rows somebody else committed.
 */
final readonly class ReconciliationCalculator
{
    /**
     * The closing balance of the last completed reconciliation on this
     * account before a date — the only honest opening figure, because it is
     * the one the bank and the books already agreed on.
     */
    public function openingFor(BankAccount $bankAccount, Carbon $periodStart): string
    {
        $previous = BankReconciliation::query()
            ->where('bank_account_id', $bankAccount->getKey())
            ->where('status', ReconciliationStatus::Completed)
            ->where('period_end', '<', $periodStart->toDateString())
            ->orderByDesc('period_end')
            ->first();

        return $previous === null ? '0.0000' : $previous->closing_balance;
    }

    /**
     * The date this account is reconciled up to, if it ever has been.
     */
    public function reconciledThrough(BankAccount $bankAccount): ?Carbon
    {
        $latest = BankReconciliation::query()
            ->where('bank_account_id', $bankAccount->getKey())
            ->where('status', ReconciliationStatus::Completed)
            ->orderByDesc('period_end')
            ->first();

        return $latest?->period_end;
    }

    /**
     * Everything the reconciliation screen needs, and everything completing
     * one has to check.
     *
     * @return array{
     *     opening: string,
     *     cleared: string,
     *     closing: string,
     *     difference: string,
     *     matched_count: int,
     *     matched_total: string,
     *     unmatched_count: int,
     *     unmatched_total: string,
     *     excluded_count: int,
     *     ledger_balance: string,
     *     unpresented_count: int,
     *     unpresented_total: string
     * }
     */
    public function figuresFor(BankReconciliation $reconciliation): array
    {
        $bankAccountId = $reconciliation->bank_account_id;
        $start = $reconciliation->period_start->toDateString();
        $end = $reconciliation->period_end->toDateString();

        $opening = BigDecimal::of($reconciliation->opening_balance)->toScale(4, RoundingMode::HalfUp);
        $closing = BigDecimal::of($reconciliation->closing_balance)->toScale(4, RoundingMode::HalfUp);

        /** @var object{matched_count: int, matched_total: ?string, unmatched_count: int, unmatched_total: ?string, excluded_count: int} $statement */
        $statement = DB::table('bank_statement_lines')
            ->where('bank_account_id', $bankAccountId)
            ->whereBetween('transaction_date', [$start, $end])
            ->selectRaw(
                'COUNT(*) FILTER (WHERE status = ?) AS matched_count, '.
                'COALESCE(SUM(amount) FILTER (WHERE status = ?), 0) AS matched_total, '.
                'COUNT(*) FILTER (WHERE status = ?) AS unmatched_count, '.
                'COALESCE(SUM(amount) FILTER (WHERE status = ?), 0) AS unmatched_total, '.
                'COUNT(*) FILTER (WHERE status = ?) AS excluded_count',
                [
                    StatementLineStatus::Matched->value,
                    StatementLineStatus::Matched->value,
                    StatementLineStatus::Unmatched->value,
                    StatementLineStatus::Unmatched->value,
                    StatementLineStatus::Excluded->value,
                ],
            )
            ->first();

        $matchedTotal = BigDecimal::of((string) ($statement->matched_total ?? '0'))
            ->toScale(4, RoundingMode::HalfUp);

        $cleared = $opening->plus($matchedTotal);
        $difference = $closing->minus($cleared);

        [$ledgerBalance, $unpresentedCount, $unpresentedTotal] =
            $this->ledgerSide($reconciliation->bankAccountOrFail(), $end);

        return [
            'opening' => (string) $opening,
            'cleared' => (string) $cleared,
            'closing' => (string) $closing,
            'difference' => (string) $difference,
            'matched_count' => (int) $statement->matched_count,
            'matched_total' => (string) $matchedTotal,
            'unmatched_count' => (int) $statement->unmatched_count,
            'unmatched_total' => (string) BigDecimal::of((string) ($statement->unmatched_total ?? '0'))
                ->toScale(4, RoundingMode::HalfUp),
            'excluded_count' => (int) $statement->excluded_count,
            'ledger_balance' => $ledgerBalance,
            'unpresented_count' => $unpresentedCount,
            'unpresented_total' => $unpresentedTotal,
        ];
    }

    /**
     * Our side: the account's balance, and what has not cleared the bank.
     *
     * @return array{0: string, 1: int, 2: string}
     */
    public function ledgerSide(BankAccount $bankAccount, string $through): array
    {
        /** @var object{balance: ?string} $balanceRow */
        $balanceRow = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', $bankAccount->account_id)
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<=', $through)
            ->selectRaw('COALESCE(SUM(jl.debit - jl.credit), 0) AS balance')
            ->first();

        /** @var object{items: int, total: ?string} $unpresented */
        $unpresented = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->leftJoin('bank_transaction_matches as m', 'm.journal_line_id', '=', 'jl.id')
            ->where('jl.account_id', $bankAccount->account_id)
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<=', $through)
            ->whereNull('m.id')
            ->selectRaw('COUNT(*) AS items, COALESCE(SUM(jl.debit - jl.credit), 0) AS total')
            ->first();

        return [
            (string) BigDecimal::of((string) ($balanceRow->balance ?? '0'))
                ->toScale(4, RoundingMode::HalfUp),
            (int) $unpresented->items,
            (string) BigDecimal::of((string) ($unpresented->total ?? '0'))
                ->toScale(4, RoundingMode::HalfUp),
        ];
    }
}
