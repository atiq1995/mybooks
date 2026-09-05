<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The dashboard.
 *
 * Phase 0 has no ledger, so every figure is genuinely unknown and is reported
 * as such. It deliberately does NOT send zeros: in an accounting product
 * "0.00" is a claim that nothing is outstanding, and that is a very different
 * statement from "this has not been computed". Real values arrive with the
 * ledger in Phase 2.
 */
final class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard/Index', [
            'metrics' => [
                [
                    'key' => 'receivables',
                    'label' => 'Total receivables',
                    'value' => null,
                    'change' => null,
                    'tone' => 'neutral',
                    'hint' => 'Awaiting the first invoice',
                ],
                [
                    'key' => 'payables',
                    'label' => 'Total payables',
                    'value' => null,
                    'change' => null,
                    'tone' => 'neutral',
                    'hint' => 'Awaiting the first bill',
                ],
                [
                    'key' => 'cash',
                    'label' => 'Cash and bank',
                    'value' => null,
                    'change' => null,
                    'tone' => 'neutral',
                    'hint' => 'No accounts connected',
                ],
                [
                    'key' => 'net_profit',
                    'label' => 'Net profit',
                    'value' => null,
                    'change' => null,
                    'tone' => 'neutral',
                    'hint' => 'Needs a posted period',
                ],
            ],
            'hasLedgerData' => false,
        ]);
    }
}
