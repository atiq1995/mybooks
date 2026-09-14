<?php

declare(strict_types=1);

namespace App\Domain\Banking\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Banking\Enums\StatementLineStatus;
use App\Domain\Banking\Exceptions\BankingRefused;
use App\Domain\Banking\Models\BankReconciliation;
use App\Domain\Banking\Services\ReconciliationCalculator;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Close a reconciliation, and freeze what it covered.
 *
 * Phase 6's exit criterion, in one action:
 *
 *   **it reconciles to zero** — the difference is recomputed here from the
 *   matches rather than trusted from the row, and a non-zero one is refused
 *   with what the figure actually is;
 *
 *   **and it cannot be silently altered afterwards** — every statement line
 *   and every match in the period is stamped with this reconciliation's id,
 *   which is what the database triggers watch. From this point they refuse
 *   every update and delete, whatever code path asks.
 *
 * Nothing posts. A reconciliation is a statement about entries that were
 * already posted, which is why it can be completed by somebody with
 * `banking.reconcile` and no posting permission at all.
 */
final readonly class CompleteReconciliation
{
    public function __construct(
        private ReconciliationCalculator $calculator,
        private AuditRecorder $audit,
    ) {}

    public function handle(BankReconciliation $reconciliation, ?User $actor = null): BankReconciliation
    {
        if ($reconciliation->isCompleted()) {
            throw BankingRefused::alreadyCompleted($reconciliation->number);
        }

        return DB::transaction(function () use ($reconciliation, $actor): BankReconciliation {
            $figures = $this->calculator->figuresFor($reconciliation);

            $difference = BigDecimal::of($figures['difference']);

            if (! $difference->isZero()) {
                throw BankingRefused::notReconciled((string) $difference);
            }

            $completedAt = Carbon::now();

            /*
             * Order matters. The lines and matches are stamped FIRST, while
             * the reconciliation is still a draft — the freeze trigger fires
             * on rows whose `reconciliation_id` is already set, so stamping
             * them after marking the parent complete would be refused by the
             * very rule it is trying to establish.
             */
            $lineIds = DB::table('bank_statement_lines')
                ->where('bank_account_id', $reconciliation->bank_account_id)
                ->whereBetween('transaction_date', [
                    $reconciliation->period_start->toDateString(),
                    $reconciliation->period_end->toDateString(),
                ])
                ->whereNull('reconciliation_id')
                ->whereIn('status', [
                    StatementLineStatus::Matched->value,
                    StatementLineStatus::Excluded->value,
                ])
                ->pluck('id')
                ->all();

            DB::table('bank_statement_lines')
                ->whereIn('id', $lineIds)
                ->update([
                    'reconciliation_id' => $reconciliation->getKey(),
                    'updated_at' => $completedAt,
                ]);

            DB::table('bank_transaction_matches')
                ->whereIn('statement_line_id', $lineIds)
                ->whereNull('reconciliation_id')
                ->update([
                    'reconciliation_id' => $reconciliation->getKey(),
                    'updated_at' => $completedAt,
                ]);

            $reconciliation->forceFill([
                'cleared_balance' => $figures['cleared'],
                'difference' => $figures['difference'],
                'status' => ReconciliationStatus::Completed,
                'completed_at' => $completedAt,
                'completed_by' => $actor?->getKey(),
            ])->save();

            $this->audit->record(
                action: 'banking.reconciliation_completed',
                subject: $reconciliation,
                description: sprintf(
                    'Reconciled %s to %s at %s — %d transactions cleared, difference %s',
                    $reconciliation->bankAccountOrFail()->name,
                    $reconciliation->period_end->toDateString(),
                    $figures['closing'],
                    $figures['matched_count'],
                    $figures['difference'],
                ),
                new: [
                    'number' => $reconciliation->number,
                    'opening' => $figures['opening'],
                    'cleared' => $figures['cleared'],
                    'closing' => $figures['closing'],
                    'difference' => $figures['difference'],
                    'matched' => $figures['matched_count'],
                    'excluded' => $figures['excluded_count'],
                    'unpresented' => $figures['unpresented_count'],
                    'lines_locked' => count($lineIds),
                ],
                actor: $actor,
            );

            return $reconciliation->refresh();
        });
    }
}
