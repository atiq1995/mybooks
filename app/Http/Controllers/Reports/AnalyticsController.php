<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\Reports\AnalyticsReport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\ResolvesReportPeriod;
use App\Support\Reports\ReportResponder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Sales and spend, ranked.
 *
 * Four views behind one route, because they are the same question asked
 * about four different columns and splitting them into four screens would
 * mean four sets of filters to keep in step.
 */
final class AnalyticsController extends Controller
{
    use ResolvesReportPeriod;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AnalyticsReport $analytics,
        private readonly ReportResponder $responder,
    ) {}

    public function __invoke(Request $request): Response|SymfonyResponse
    {
        $this->authorizeReport($request);

        $organization = $this->tenant->organization();

        $view = $request->string('view')->toString();
        $view = in_array($view, AnalyticsReport::VIEWS, true) ? $view : 'customers';

        $period = $this->reportPeriod($request, $organization);
        $comparison = $this->comparisonPeriod($request, $period);

        return $this->responder->respond(
            $request,
            $this->analytics->build($view, $period, $comparison),
            'Reports/Statement',
            [
                'organizationName' => $organization->name,
                'route' => 'reports.analytics',
                'dateMode' => 'range',
                'filters' => [
                    'preset' => $request->string('preset')->toString() ?: 'this_year',
                    'from' => $request->string('from')->toString(),
                    'to' => $request->string('to')->toString(),
                    'compare' => $request->string('compare')->toString(),
                    'zero' => false,
                    'view' => $view,
                ],
                'presetOptions' => $this->presetOptions(),
                'comparisonOptions' => $this->comparisonOptions(),
                'viewOptions' => [
                    ['value' => 'customers', 'label' => 'Sales by customer'],
                    ['value' => 'items', 'label' => 'Sales by item'],
                    ['value' => 'categories', 'label' => 'Spend by category'],
                    ['value' => 'vendors', 'label' => 'Spend by vendor'],
                ],
            ],
        );
    }
}
