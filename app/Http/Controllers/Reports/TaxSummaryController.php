<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\Reports\TaxSummaryReport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\ResolvesReportPeriod;
use App\Support\Reports\ReportResponder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The tax return summary.
 *
 * Defaults to the quarter rather than the financial year, because that is the
 * period a return is usually filed for and the default a person is most
 * likely to want without changing anything.
 */
final class TaxSummaryController extends Controller
{
    use ResolvesReportPeriod;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly TaxSummaryReport $taxSummary,
        private readonly ReportResponder $responder,
    ) {}

    public function __invoke(Request $request): Response|SymfonyResponse
    {
        $this->authorizeReport($request);

        $organization = $this->tenant->organization();

        $request->query->set(
            'preset',
            $request->string('preset')->toString() ?: 'this_quarter',
        );

        $period = $this->reportPeriod($request, $organization);

        return $this->responder->respond(
            $request,
            $this->taxSummary->build($period),
            'Reports/Statement',
            [
                'organizationName' => $organization->name,
                'route' => 'reports.tax-summary',
                'dateMode' => 'range',
                'filters' => [
                    'preset' => $request->string('preset')->toString(),
                    'from' => $request->string('from')->toString(),
                    'to' => $request->string('to')->toString(),
                    'compare' => '',
                    'zero' => false,
                ],
                'presetOptions' => $this->presetOptions(),
                // A tax return has no comparison column: a period is filed or
                // it is not, and putting last quarter beside it invites
                // somebody to file the wrong figure.
                'comparisonOptions' => [],
            ],
        );
    }
}
