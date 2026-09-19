<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Domain\Access\Enums\Permission;
use App\Domain\Reports\Reports\BalanceSheetReport;
use App\Domain\Reports\Reports\CashFlowReport;
use App\Domain\Reports\Reports\ProfitAndLossReport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\ResolvesReportPeriod;
use App\Support\Reports\ReportResponder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The three statements.
 *
 * One controller rather than three, because they differ only in which report
 * object builds the table — the permission, the period parsing, the export
 * handling and the shape of the props are identical, and three copies of that
 * is three places for them to drift apart.
 *
 * Exporting is not a separate route either. `?format=csv` on the same URL
 * returns the same report as a file, which means an exported figure cannot
 * have been produced by a different query than the one on screen.
 */
final class FinancialStatementController extends Controller
{
    use ResolvesReportPeriod;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProfitAndLossReport $profitAndLoss,
        private readonly BalanceSheetReport $balanceSheet,
        private readonly CashFlowReport $cashFlow,
        private readonly ReportResponder $responder,
    ) {}

    public function profitAndLoss(Request $request): Response|SymfonyResponse
    {
        $this->authorizeReport($request);

        $organization = $this->tenant->organization();
        $period = $this->reportPeriod($request, $organization);
        $comparison = $this->comparisonPeriod($request, $period);

        $table = $this->profitAndLoss->build($period, $comparison, $request->boolean('zero'));

        return $this->responder->respond(
            $request,
            $table,
            'Reports/Statement',
            $this->props($request, $organization->name, 'reports.profit-and-loss'),
        );
    }

    public function balanceSheet(Request $request): Response|SymfonyResponse
    {
        $this->authorizeReport($request);

        $organization = $this->tenant->organization();

        $asOf = $this->asOf($request);
        $comparisonAsOf = match ($request->string('compare')->toString()) {
            'previous' => $this->previousMonthEnd($asOf),
            'last_year' => $asOf->copy()->subYearNoOverflow(),
            default => null,
        };

        $table = $this->balanceSheet->build($asOf, $comparisonAsOf, $request->boolean('zero'));

        return $this->responder->respond(
            $request,
            $table,
            'Reports/Statement',
            [
                ...$this->props($request, $organization->name, 'reports.balance-sheet'),
                // A balance sheet is as AT a date, so the filter it offers is
                // a single date rather than a span. Showing a from/to here
                // would invite somebody to read it as a period statement.
                'dateMode' => 'as_at',
                'filters' => [
                    'as_of' => $asOf->toDateString(),
                    'compare' => $request->string('compare')->toString(),
                    'zero' => $request->boolean('zero'),
                ],
            ],
        );
    }

    public function cashFlow(Request $request): Response|SymfonyResponse
    {
        $this->authorizeReport($request);

        $organization = $this->tenant->organization();
        $period = $this->reportPeriod($request, $organization);

        $table = $this->cashFlow->build($period, $request->boolean('zero'));

        return $this->responder->respond(
            $request,
            $table,
            'Reports/Statement',
            [
                ...$this->props($request, $organization->name, 'reports.cash-flow'),
                // Two periods of a cash flow cannot be shown side by side
                // honestly: the opening balance of one is the closing of the
                // other, and a "change" column between them is nonsense.
                'comparisonOptions' => [],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function props(Request $request, string $organizationName, string $route): array
    {
        return [
            'organizationName' => $organizationName,
            'route' => $route,
            'dateMode' => 'range',
            'filters' => [
                'preset' => $request->string('preset')->toString() ?: 'this_year',
                'from' => $request->string('from')->toString(),
                'to' => $request->string('to')->toString(),
                'compare' => $request->string('compare')->toString(),
                'zero' => $request->boolean('zero'),
            ],
            'presetOptions' => $this->presetOptions(),
            'comparisonOptions' => $this->comparisonOptions(),
        ];
    }

    /**
     * The end of the month before the one being reported on.
     *
     * A balance sheet compared against "the previous period" means the last
     * comparable snapshot, and for a date-based statement that is a month
     * end — not the same date a month earlier, which straddles two months of
     * activity and compares nothing in particular.
     */
    private function previousMonthEnd(Carbon $asOf): Carbon
    {
        return $asOf->copy()->startOfMonth()->subDay();
    }
}
