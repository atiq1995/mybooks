<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A designed page for modules the navigation advertises but that have not
 * been built yet.
 *
 * The alternative — hiding unbuilt modules — would mean the information
 * architecture changes shape under users at every release, and would hide the
 * product's own plan from the people using it. A dead link reads as broken; a
 * page that says which phase delivers the module reads as under construction,
 * which is the truth.
 *
 * Each module deletes its route from here as it lands. When the last one
 * goes, so does this controller.
 *
 * @see ROADMAP.md
 */
final class ModulePlaceholderController extends Controller
{
    /**
     * Which roadmap phase delivers each area.
     *
     * `sections` is the list of submodule slugs a module may make a promise
     * about. An allowlist rather than a wildcard, on purpose — see
     * __invoke().
     *
     * @var array<string, array{phase: int, title: string, summary: string, sections: list<string>}>
     */
    private const array MODULES = [
        // Sales has landed. Only recurring invoices are still to come:
        // they need a template model and the scheduler, which is its own
        // piece of work rather than a variation on an invoice.
        'sales' => [
            'phase' => 4,
            'title' => 'Recurring Invoices',
            'summary' => 'Invoice templates that generate on a schedule, each posting at its own date.',
            'sections' => ['recurring-invoices'],
        ],
        /*
         * Purchases has landed in full: orders, bills, vendor credits,
         * payments, payables and withholding at payment all have real
         * screens, and vendors live on the contacts list with everyone else.
         * There is nothing left here to promise, so the module has no entry —
         * which makes /purchases/anything-else a 404 rather than a page
         * announcing a feature that already exists.
         */
        'expenses' => [
            'phase' => 5,
            'title' => 'Expenses',
            'summary' => 'Expense entry with receipt capture, categories, mileage and an approval workflow.',
            'sections' => ['approvals', 'categories', 'mileage'],
        ],
        'banking' => [
            'phase' => 6,
            'title' => 'Banking',
            'summary' => 'Bank and cash accounts, statement import, transaction matching and reconciliation.',
            'sections' => ['accounts', 'transactions', 'reconciliation', 'transfers'],
        ],
        // The ledger itself has landed; only opening balances are still to
        // come, and they wait on Phase 3's contacts and items — most opening
        // balances are unpaid invoices, not plain journal lines.
        'accounting' => [
            'phase' => 3,
            'title' => 'Opening Balances',
            'summary' => 'Bringing forward closing balances from a previous system, including unpaid invoices and bills.',
            'sections' => ['opening-balances'],
        ],
        'inventory' => [
            'phase' => 8,
            'title' => 'Inventory',
            'summary' => 'Items with stock tracking, warehouses, adjustments and weighted-average valuation.',
            'sections' => ['items', 'warehouses', 'adjustments', 'valuation'],
        ],
        'reports' => [
            'phase' => 7,
            'title' => 'Reports',
            'summary' => 'Profit and loss, balance sheet, cash flow, ageing, tax summary — each drilling through to its journal lines.',
            // Named individually once they exist; the section list is empty
            // until then, so /reports/profit-and-loss is honestly a 404.
            'sections' => [],
        ],
        'contacts' => [
            'phase' => 3,
            'title' => 'Contacts',
            'summary' => 'Customers and vendors in one place, with their transactions, balances and statements.',
            'sections' => [],
        ],
        'documents' => [
            'phase' => 9,
            'title' => 'Documents',
            'summary' => 'Attachments and files, stored in object storage and linked to the records they belong to.',
            'sections' => [],
        ],
        'settings' => [
            'phase' => 1,
            'title' => 'Settings',
            'summary' => 'Organisation, users, roles, taxes, currencies, templates, automation and security.',
            // Every settings screen that exists has its own route, and those
            // are registered before this one.
            'sections' => [],
        ],
        'organizations' => [
            'phase' => 1,
            'title' => 'Organisations',
            'summary' => 'Creating and switching between organisations, and inviting people into them.',
            'sections' => [],
        ],
    ];

    public function __invoke(Request $request, string $module, ?string $submodule = null): Response
    {
        $definition = self::MODULES[$module] ?? abort(404);

        /*
         * An unknown section is not found — it is not "arriving in Phase 4".
         *
         * This page makes a promise, and a promise has to be about something
         * somebody intends to build. Matching any segment would mean every
         * typo under a live module answers 200 and invents a feature:
         * /sales/widgets would announce Recurring Invoices, and a link left
         * behind by a renamed screen would read as a roadmap entry rather
         * than the dead link it actually is.
         */
        if ($submodule !== null && ! in_array($submodule, $definition['sections'], true)) {
            abort(404);
        }

        return Inertia::render('ModulePlaceholder', [
            'module' => $definition['title'],
            'phase' => $definition['phase'],
            'summary' => $definition['summary'],
            'section' => $submodule === null
                ? null
                : ucwords(str_replace('-', ' ', $submodule)),
        ]);
    }
}
