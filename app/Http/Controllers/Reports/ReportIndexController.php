<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Domain\Access\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The reports hub.
 *
 * Every report in the product, including the ones that arrived with earlier
 * phases and live under Accounting, Sales and Purchases. A person looking for
 * "the reports" should find all of them in one place rather than having to
 * know which module happened to build each one.
 *
 * Each entry says what the report ANSWERS rather than repeating its title.
 * "Profit and loss — Profit and loss" helps nobody choose.
 */
final class ReportIndexController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function __invoke(Request $request): Response
    {
        $this->authorize(Permission::ReportsView->value);

        $organization = $this->tenant->organization();

        return Inertia::render('Reports/Index', [
            'groups' => [
                [
                    'label' => 'Financial statements',
                    'description' => 'The three that reconcile to the ledger, to the cent.',
                    'reports' => [
                        [
                            'title' => 'Profit and loss',
                            'summary' => 'What was earned and what it cost, over a period.',
                            'href' => '/reports/profit-and-loss',
                        ],
                        [
                            'title' => 'Balance sheet',
                            'summary' => 'What the business owns and owes, at a date.',
                            'href' => '/reports/balance-sheet',
                        ],
                        [
                            'title' => 'Cash flow',
                            'summary' => 'Why the bank balance moved by what it moved.',
                            'href' => '/reports/cash-flow',
                        ],
                    ],
                ],
                [
                    'label' => 'Tax',
                    'description' => 'Filed per component, on the documents that posted.',
                    'reports' => [
                        [
                            'title' => 'Tax summary',
                            'summary' => 'Output tax, reclaimable input tax, and what is payable.',
                            'href' => '/reports/tax-summary',
                        ],
                    ],
                ],
                [
                    'label' => 'Analysis',
                    'description' => 'Where the money came from, and where it went.',
                    'reports' => [
                        [
                            'title' => 'Sales by customer',
                            'summary' => 'Who buys, ranked, net of tax.',
                            'href' => '/reports/analytics?view=customers',
                        ],
                        [
                            'title' => 'Sales by item',
                            'summary' => 'What sells, ranked, net of tax.',
                            'href' => '/reports/analytics?view=items',
                        ],
                        [
                            'title' => 'Spend by category',
                            'summary' => 'Bills and expenses together, by account.',
                            'href' => '/reports/analytics?view=categories',
                        ],
                        [
                            'title' => 'Spend by vendor',
                            'summary' => 'Who is paid, ranked.',
                            'href' => '/reports/analytics?view=vendors',
                        ],
                    ],
                ],
                [
                    'label' => 'Ledger',
                    'description' => 'The underlying records every figure above drills into.',
                    'reports' => [
                        [
                            'title' => 'Trial balance',
                            'summary' => 'Every account as at a date, and whether the books balance.',
                            'href' => '/accounting/trial-balance',
                        ],
                        [
                            'title' => 'General ledger',
                            'summary' => 'Every posting on an account, with a running balance.',
                            'href' => '/accounting/general-ledger',
                        ],
                        [
                            'title' => 'Receivables ageing',
                            'summary' => 'Who owes what, and for how long.',
                            'href' => '/sales/receivables',
                        ],
                        [
                            'title' => 'Payables ageing',
                            'summary' => 'What is owed, and when it falls due.',
                            'href' => '/purchases/payables',
                        ],
                    ],
                ],
            ],
            'baseCurrency' => $organization->base_currency,
            'organizationName' => $organization->name,
        ]);
    }
}
