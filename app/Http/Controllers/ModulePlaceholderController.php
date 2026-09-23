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
     * No module left here promises anything BELOW itself any more — every one
     * that did has landed. `sections` therefore stayed only as long as one of
     * them named a submodule; see __invoke() for what replaced it.
     *
     * @var array<string, array{phase: int, title: string, summary: string}>
     */
    private const array MODULES = [
        /*
         * Sales has landed in full, recurring invoices included, so it has no
         * entry here either.
         */
        /*
         * Purchases has landed in full: orders, bills, vendor credits,
         * payments, payables and withholding at payment all have real
         * screens, and vendors live on the contacts list with everyone else.
         * There is nothing left here to promise, so the module has no entry —
         * which makes /purchases/anything-else a 404 rather than a page
         * announcing a feature that already exists.
         */
        /*
         * Expenses has landed: entry, receipts, mileage, approval and
         * billable expenses all have real screens, and approvals and mileage
         * are routes of their own rather than sections still to come. There
         * is nothing left to promise, so the module has no entry — which
         * makes /expenses/anything-else a 404 rather than a page announcing
         * something that already exists.
         *
         * Categories are the chart of accounts. An expense line charges an
         * expense or asset account directly, which is what a category IS —
         * a second table naming the same thing would be a second source of
         * truth about where a cost belongs.
         */
        /*
         * Banking has landed in full — accounts, import, matching,
         * reconciliation and transfers — so it has no entry here, and
         * `/banking/anything-else` is a 404 rather than a page announcing
         * something that already works.
         */
        /*
         * Accounting has landed in full, opening balances included — so the
         * module has no entry here. `/accounting/anything-else` is now a 404
         * rather than a page announcing something that already exists.
         */
        /*
         * Inventory has landed — items with stock, warehouses, adjustments,
         * transfers and valuation — so it has no entry here, and
         * `/inventory/anything-else` is a 404 rather than a page announcing
         * something that already works.
         */
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
            // Every settings screen that exists has its own route, and those
            // are registered before this one.
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

        /*
         * A SECTION of an unbuilt module is not found — it is not "arriving in
         * Phase 9".
         *
         * This page makes a promise, and a promise has to be about something
         * somebody intends to build. Matching any segment would mean every
         * typo under a module answers 200 and invents a feature, and a link
         * left behind by a renamed screen would read as a roadmap entry
         * rather than the dead link it actually is.
         *
         * There used to be a per-module allowlist of section slugs. Every
         * module that named one has since landed, so the list is empty
         * everywhere and the rule collapses to this: the module page itself,
         * and nothing under it.
         */
        if ($submodule !== null) {
            abort(404);
        }

        return Inertia::render('ModulePlaceholder', [
            'module' => $definition['title'],
            'phase' => $definition['phase'],
            'summary' => $definition['summary'],
            'section' => null,
        ]);
    }
}
