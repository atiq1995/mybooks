<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a financial year and its twelve monthly periods.
 *
 * The year starts in the organisation's own fiscal start month, so a Pakistani
 * business gets July-to-June and its first period is July — not January.
 * Labelling reflects that: a year spanning two calendar years is "2026-27".
 *
 * @see ACCOUNTING_RULES.md §7
 */
final readonly class CreateFiscalYear
{
    public function __construct(
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  int|null  $startingYear  calendar year the fiscal year opens in;
     *                                  defaults to the one covering today
     */
    public function handle(
        Organization $organization,
        ?int $startingYear = null,
        ?User $actor = null,
    ): FiscalYear {
        $startMonth = $organization->fiscal_year_start_month;
        $startingYear ??= $this->currentFiscalStartYear($startMonth);

        $startsOn = Carbon::create($startingYear, $startMonth, 1)?->startOfDay();

        if ($startsOn === null) {
            throw new \InvalidArgumentException("Cannot build a fiscal year from {$startingYear}-{$startMonth}.");
        }

        $endsOn = $startsOn->copy()->addYear()->subDay();

        return DB::transaction(function () use ($organization, $startsOn, $endsOn, $actor): FiscalYear {
            $year = new FiscalYear;

            $year->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organization->getKey(),
                'label' => $this->label($startsOn, $endsOn),
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'status' => 'open',
            ])->save();

            $cursor = $startsOn->copy();

            for ($sequence = 1; $sequence <= 12; $sequence++) {
                $periodStart = $cursor->copy()->startOfMonth();
                $periodEnd = $cursor->copy()->endOfMonth();

                $period = new FiscalPeriod;

                $period->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->getKey(),
                    'fiscal_year_id' => $year->getKey(),
                    'sequence' => $sequence,
                    // The month's own name, which is what an accountant looks
                    // for — not "Period 1".
                    'label' => $periodStart->format('M Y'),
                    'starts_on' => $periodStart->toDateString(),
                    'ends_on' => $periodEnd->toDateString(),
                    'status' => 'open',
                ])->save();

                $cursor->addMonthNoOverflow();
            }

            $this->audit->record(
                action: 'accounting.fiscal_year_created',
                subject: $year,
                description: "Opened financial year {$year->label}",
                new: [
                    'label' => $year->label,
                    'starts_on' => $year->starts_on->toDateString(),
                    'ends_on' => $year->ends_on->toDateString(),
                ],
                actor: $actor,
            );

            return $year;
        });
    }

    /**
     * Which calendar year the CURRENT fiscal year began in.
     *
     * With a July start, January 2027 belongs to the year that opened in July
     * 2026 — so the answer is not simply "this year".
     */
    private function currentFiscalStartYear(int $startMonth): int
    {
        $now = Carbon::now();

        return (int) $now->format('n') >= $startMonth
            ? (int) $now->format('Y')
            : (int) $now->format('Y') - 1;
    }

    /**
     * "2026" for a calendar year, "2026-27" for one that spans two.
     */
    private function label(Carbon $startsOn, Carbon $endsOn): string
    {
        if ($startsOn->format('Y') === $endsOn->format('Y')) {
            return $startsOn->format('Y');
        }

        return $startsOn->format('Y').'-'.$endsOn->format('y');
    }
}
