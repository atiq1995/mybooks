<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\ExchangeRateService;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Period-end revaluation of foreign-currency balances.
 *
 * An account held in another currency has two balances: what it holds in that
 * currency, and what those holdings were worth in base currency on the days
 * they arrived. Rates move, so by period end the second figure is stale — and
 * the balance sheet is stated in base currency.
 *
 * This posts the difference to FX gain/loss. The gain is UNREALISED: nothing
 * has been settled, the money has not moved, and next period's rate will be
 * different again. So the entry is reversed on the first day of the next
 * period, leaving the accounts carrying their original rates and only the
 * reported period bearing the adjustment.
 *
 * Realised gain and loss is a different thing entirely — it arises on
 * settlement, at the rate on the original document, and belongs to the
 * payment actions.
 *
 * @see ACCOUNTING_RULES.md §8, §4.11
 */
final readonly class RevalueForeignCurrencyBalances
{
    public function __construct(
        private PostJournalEntry $postJournalEntry,
        private ExchangeRateService $rates,
        private AuditRecorder $audit,
        private TenantContext $tenant,
    ) {}

    /**
     * @return array{entry: JournalEntry|null, reversal: JournalEntry|null, adjustments: list<array<string, string>>}
     */
    public function handle(Carbon $asOf, ?User $actor = null): array
    {
        $organization = $this->tenant->organization();
        $base = $organization->base_currency;

        $fxAccount = Account::query()
            ->where('system_role', SystemAccount::FxGainLoss->value)
            ->first();

        if ($fxAccount === null) {
            throw PostingRefused::missingSystemAccount(SystemAccount::FxGainLoss->value);
        }

        $adjustments = $this->adjustmentsAt($asOf, $base);

        if ($adjustments === []) {
            return ['entry' => null, 'reversal' => null, 'adjustments' => []];
        }

        return DB::transaction(function () use ($adjustments, $fxAccount, $asOf, $base, $actor): array {
            $entry = $this->postAdjustment($adjustments, $fxAccount, $asOf, $base, $actor);

            /*
             * Reversed on the first day of the next period, in the same
             * transaction. An unrealised adjustment that outlives its period
             * would be counted twice the next time revaluation ran, and the
             * carrying value of every foreign balance would drift away from
             * the rates its transactions actually happened at.
             */
            $reversal = $this->postReversal($adjustments, $fxAccount, $asOf, $base, $actor);

            $this->audit->record(
                action: 'accounting.revalued',
                subject: $entry,
                description: sprintf(
                    'Revalued %d foreign-currency balance(s) at %s',
                    count($adjustments),
                    $asOf->toDateString(),
                ),
                new: [
                    'as_of' => $asOf->toDateString(),
                    'entry_no' => $entry->entry_no,
                    'reversal_no' => $reversal->entry_no,
                    'adjustments' => $adjustments,
                ],
                actor: $actor,
            );

            return ['entry' => $entry, 'reversal' => $reversal, 'adjustments' => $adjustments];
        });
    }

    /**
     * What each foreign-currency account needs adjusting by.
     *
     * A positive `difference` means the base-currency carrying value is too
     * LOW and the account must be debited to bring it up.
     *
     * @return list<array<string, string>>
     */
    public function adjustmentsAt(Carbon $asOf, string $base): array
    {
        $accounts = Account::query()
            ->postable()
            ->whereNotNull('currency')
            ->where('currency', '!=', $base)
            ->orderBy('code')
            ->get();

        if ($accounts->isEmpty()) {
            return [];
        }

        $balances = $this->balancesAt(
            array_values($accounts->map(static fn (Account $a): string => $a->id)->all()),
            $asOf,
        );
        $adjustments = [];

        foreach ($accounts as $account) {
            $balance = $balances[$account->id] ?? null;

            if ($balance === null) {
                continue;
            }

            /** @var string $currency */
            $currency = $account->currency;

            // Nothing held means nothing to revalue, whatever the rate did.
            if ($balance['foreign']->isZero()) {
                continue;
            }

            $rate = $this->rates->rate($currency, $base, $asOf);

            $revalued = $balance['foreign']
                ->multipliedBy(BigDecimal::of($rate))
                ->toScale(4, RoundingMode::HalfUp);

            $difference = $revalued->minus($balance['carrying']);

            // Rounding can leave a difference of nothing; a zero-amount line
            // is refused, and rightly.
            if ($difference->isZero()) {
                continue;
            }

            $adjustments[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'currency' => $currency,
                'rate' => $rate,
                'foreign_balance' => (string) $balance['foreign'],
                'carrying_value' => (string) $balance['carrying'],
                'revalued_to' => (string) $revalued,
                'difference' => (string) $difference,
            ];
        }

        return $adjustments;
    }

    /**
     * Each account's balance in its own currency and in base currency.
     *
     * @param  list<string>  $accountIds
     * @return array<string, array{foreign: BigDecimal, carrying: BigDecimal}>
     */
    private function balancesAt(array $accountIds, Carbon $asOf): array
    {
        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.account_id', $accountIds)
            ->whereDate('journal_entries.entry_date', '<=', $asOf->toDateString())
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id')
            ->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) AS foreign_net')
            ->selectRaw('COALESCE(SUM(journal_lines.debit_base - journal_lines.credit_base), 0) AS base_net')
            ->get();

        $balances = [];

        foreach ($rows as $row) {
            /** @var object{account_id: string, foreign_net: string, base_net: string} $row */
            $balances[$row->account_id] = [
                'foreign' => BigDecimal::of((string) $row->foreign_net),
                'carrying' => BigDecimal::of((string) $row->base_net),
            ];
        }

        return $balances;
    }

    /**
     * @param  list<array<string, string>>  $adjustments
     */
    private function postAdjustment(
        array $adjustments,
        Account $fxAccount,
        Carbon $asOf,
        string $base,
        ?User $actor,
    ): JournalEntry {
        $lines = [];
        $total = BigDecimal::zero();

        foreach ($adjustments as $adjustment) {
            $difference = BigDecimal::of($adjustment['difference']);
            $total = $total->plus($difference);

            $memo = sprintf(
                '%s %s at %s',
                $adjustment['foreign_balance'],
                $adjustment['currency'],
                $adjustment['rate'],
            );

            $lines[] = $difference->isPositive()
                ? JournalLineDraft::debit($adjustment['account_id'], (string) $difference, $memo)
                : JournalLineDraft::credit($adjustment['account_id'], (string) $difference->abs(), $memo);
        }

        // The balancing line: a net debit to the accounts is a gain, which
        // credits FX gain/loss.
        $lines[] = $total->isPositive()
            ? JournalLineDraft::credit($fxAccount->id, (string) $total, 'Unrealised FX gain')
            : JournalLineDraft::debit($fxAccount->id, (string) $total->abs(), 'Unrealised FX loss');

        return $this->postJournalEntry->handle(
            draft: JournalDraft::inBaseCurrency(
                date: $asOf,
                currency: $base,
                lines: $lines,
                // The date is the source, so re-running revaluation for the
                // same date is refused by the idempotency index rather than
                // doubling the adjustment.
                source: ['revaluation', $this->sourceIdFor($asOf), 'revalue'],
                memo: "Unrealised FX revaluation at {$asOf->toDateString()}",
            ),
            actor: $actor,
        );
    }

    /**
     * The mirror image, dated the following day.
     *
     * Posted as its own entry rather than through ReverseJournalEntry, because
     * this is not a correction: the adjustment was right for the period it
     * reported, and marking it `reversed` would suggest it had been a mistake.
     *
     * @param  list<array<string, string>>  $adjustments
     */
    private function postReversal(
        array $adjustments,
        Account $fxAccount,
        Carbon $asOf,
        string $base,
        ?User $actor,
    ): JournalEntry {
        $lines = [];
        $total = BigDecimal::zero();

        foreach ($adjustments as $adjustment) {
            $difference = BigDecimal::of($adjustment['difference']);
            $total = $total->plus($difference);

            $lines[] = $difference->isPositive()
                ? JournalLineDraft::credit(
                    $adjustment['account_id'],
                    (string) $difference,
                    'Reversing the period-end revaluation',
                )
                : JournalLineDraft::debit(
                    $adjustment['account_id'],
                    (string) $difference->abs(),
                    'Reversing the period-end revaluation',
                );
        }

        $lines[] = $total->isPositive()
            ? JournalLineDraft::debit($fxAccount->id, (string) $total, 'Reversing unrealised FX gain')
            : JournalLineDraft::credit($fxAccount->id, (string) $total->abs(), 'Reversing unrealised FX loss');

        $nextDay = $asOf->copy()->addDay();

        return $this->postJournalEntry->handle(
            draft: JournalDraft::inBaseCurrency(
                date: $nextDay,
                currency: $base,
                lines: $lines,
                source: ['revaluation', $this->sourceIdFor($asOf), 'unwind'],
                memo: "Reversal of the FX revaluation at {$asOf->toDateString()}",
            ),
            actor: $actor,
        );
    }

    /**
     * A stable id derived from the organisation and the date.
     *
     * Deterministic on purpose: the source id is what makes the idempotency
     * index refuse a second revaluation of the same date, rather than
     * cheerfully doubling the adjustment. A UUID v5 over
     * "<organisation>:<date>" gives the same answer every time without a
     * lookup table.
     */
    private function sourceIdFor(Carbon $asOf): string
    {
        return (string) Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'my-books:revaluation:'.$this->tenant->organization()->id.':'.$asOf->toDateString(),
        );
    }

    public static function permission(): Permission
    {
        return Permission::AccountingPost;
    }
}
