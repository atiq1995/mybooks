<?php

declare(strict_types=1);

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Services\DocumentNumberGenerator;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Banking\Exceptions\BankingRefused;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankReconciliation;
use App\Domain\Banking\Services\ReconciliationCalculator;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Open a reconciliation for a period.
 *
 * The opening balance is NOT accepted from the caller after the first one.
 * It is the previous completed reconciliation's closing balance, because that
 * is the figure the bank and the books already agreed on — letting somebody
 * type a different one would break the chain silently, and every period after
 * it would reconcile to a figure with no provenance.
 *
 * Two refusals keep the chain intact: one reconciliation open per account at
 * a time, and a period that cannot start before the last one ended.
 */
final readonly class StartReconciliation
{
    public function __construct(
        private TenantContext $tenant,
        private ReconciliationCalculator $calculator,
        private DocumentNumberGenerator $numbers,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        BankAccount $bankAccount,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $closingBalance,
        ?string $openingBalance = null,
        ?string $notes = null,
        ?User $actor = null,
    ): BankReconciliation {
        $open = BankReconciliation::query()
            ->where('bank_account_id', $bankAccount->getKey())
            ->where('status', ReconciliationStatus::Draft)
            ->first();

        if ($open !== null) {
            throw BankingRefused::reconciliationInProgress($open->number);
        }

        $through = $this->calculator->reconciledThrough($bankAccount);

        if ($through !== null && $periodStart->lessThanOrEqualTo($through)) {
            throw BankingRefused::periodOverlapsCompleted($through->toDateString());
        }

        $organization = $this->tenant->organization();

        return DB::transaction(function () use (
            $bankAccount,
            $periodStart,
            $periodEnd,
            $closingBalance,
            $openingBalance,
            $notes,
            $actor,
            $organization,
            $through,
        ): BankReconciliation {
            /*
             * The first reconciliation on an account may state its own
             * opening balance — there is nothing before it to inherit from.
             * Every one after that inherits, and the parameter is ignored.
             */
            $opening = $through === null
                ? BigDecimal::of($openingBalance ?? '0')->toScale(4, RoundingMode::HalfUp)
                : BigDecimal::of($this->calculator->openingFor($bankAccount, $periodStart))
                    ->toScale(4, RoundingMode::HalfUp);

            $closing = BigDecimal::of($closingBalance)->toScale(4, RoundingMode::HalfUp);

            $reconciliation = new BankReconciliation;

            $reconciliation->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organization->getKey(),
                'bank_account_id' => $bankAccount->getKey(),
                'number' => $this->numbers->next('reconciliation', $periodEnd),
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'opening_balance' => (string) $opening,
                'closing_balance' => (string) $closing,
                // Nothing is counted as cleared until the figures are
                // recomputed from the matches, which happens on completion.
                'cleared_balance' => (string) $opening,
                'difference' => (string) $closing->minus($opening),
                'status' => ReconciliationStatus::Draft,
                'notes' => $notes,
                'created_by' => $actor?->getKey(),
            ])->save();

            $this->audit->record(
                action: 'banking.reconciliation_started',
                subject: $reconciliation,
                description: sprintf(
                    'Started reconciliation %s on %s for %s to %s',
                    $reconciliation->number,
                    $bankAccount->name,
                    $periodStart->toDateString(),
                    $periodEnd->toDateString(),
                ),
                new: [
                    'opening' => (string) $opening,
                    'closing' => (string) $closing,
                ],
                actor: $actor,
            );

            return $reconciliation->refresh();
        });
    }

    /**
     * Abandon a reconciliation that was started by mistake.
     *
     * Only while it is a draft — the trigger refuses a completed one, and
     * nothing here tries to talk it round. Matches made during it survive:
     * they are somebody's judgement about what a line refers to, and that is
     * no less true because the period was wrong.
     */
    public function abandon(BankReconciliation $reconciliation, ?User $actor = null): void
    {
        if ($reconciliation->isCompleted()) {
            throw BankingRefused::alreadyCompleted($reconciliation->number);
        }

        DB::transaction(function () use ($reconciliation, $actor): void {
            $this->audit->record(
                action: 'banking.reconciliation_abandoned',
                subject: $reconciliation,
                description: "Abandoned reconciliation {$reconciliation->number}",
                actor: $actor,
            );

            $reconciliation->delete();
        });
    }
}
