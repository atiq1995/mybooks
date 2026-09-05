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
     * @var array<string, array{phase: int, title: string, summary: string}>
     */
    private const array MODULES = [
        'sales' => [
            'phase' => 3,
            'title' => 'Sales',
            'summary' => 'Customers, estimates, sales orders, invoices, recurring invoices, payments received and credit notes.',
        ],
        'purchases' => [
            'phase' => 4,
            'title' => 'Purchases',
            'summary' => 'Vendors, purchase orders, bills, payments made and vendor credits, including withholding tax at payment.',
        ],
        'expenses' => [
            'phase' => 5,
            'title' => 'Expenses',
            'summary' => 'Expense entry with receipt capture, categories, mileage and an approval workflow.',
        ],
        'banking' => [
            'phase' => 6,
            'title' => 'Banking',
            'summary' => 'Bank and cash accounts, statement import, transaction matching and reconciliation.',
        ],
        'accounting' => [
            'phase' => 2,
            'title' => 'Accounting',
            'summary' => 'Chart of accounts, the ledger, manual journals, fiscal periods, general ledger and trial balance.',
        ],
        'inventory' => [
            'phase' => 8,
            'title' => 'Inventory',
            'summary' => 'Items with stock tracking, warehouses, adjustments and weighted-average valuation.',
        ],
        'reports' => [
            'phase' => 7,
            'title' => 'Reports',
            'summary' => 'Profit and loss, balance sheet, cash flow, ageing, tax summary — each drilling through to its journal lines.',
        ],
        'contacts' => [
            'phase' => 3,
            'title' => 'Contacts',
            'summary' => 'Customers and vendors in one place, with their transactions, balances and statements.',
        ],
        'documents' => [
            'phase' => 9,
            'title' => 'Documents',
            'summary' => 'Attachments and files, stored in object storage and linked to the records they belong to.',
        ],
        'settings' => [
            'phase' => 1,
            'title' => 'Settings',
            'summary' => 'Organisation, users, roles, taxes, currencies, templates, automation and security.',
        ],
        'organizations' => [
            'phase' => 1,
            'title' => 'Organisations',
            'summary' => 'Creating and switching between organisations, and inviting people into them.',
        ],
    ];

    public function __invoke(Request $request, string $module, ?string $submodule = null): Response
    {
        $definition = self::MODULES[$module] ?? abort(404);

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
