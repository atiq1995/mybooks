<?php

declare(strict_types=1);

use App\Domain\Access\Enums\Role;
use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
|---------------------------------------------------------------------------
| The report screens
|---------------------------------------------------------------------------
|
| The accounting suite proves the figures. These cover the HTTP layer, and
| two things that only exist here:
|
|   an export is the SAME report as the screen — asserted by finding the
|   screen's own figure inside the CSV, the spreadsheet and the print
|   document, rather than by trusting that they share a code path;
|
|   reading a report and taking a copy of it out of the building are
|   different permissions. A bookkeeper may read; only a role with
|   `reports.export` may download.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-30');

    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->owner = actingAsMember($this->organization, Role::Owner->value);

    $post = app(PostJournalEntry::class);

    $entry = function (string $on, array $lines, ?string $memo = null) use ($post): void {
        $drafts = [];

        foreach ($lines as [$code, $side, $amount]) {
            $drafts[] = $side === 'debit'
                ? JournalLineDraft::debit(ledgerAccount($code)->id, $amount, $memo)
                : JournalLineDraft::credit(ledgerAccount($code)->id, $amount, $memo);
        }

        $post->handle(JournalDraft::inBaseCurrency(
            date: Carbon::parse($on),
            currency: $this->organization->base_currency,
            lines: $drafts,
            source: ['manual', (string) Str::uuid7(), 'issue'],
            memo: $memo,
        ), actor: $this->owner);
    };

    // One sale, one cost. Enough for every statement to have something to say.
    $entry('2026-07-05', [
        ['1020', 'debit', '500000.00'],
        ['4010', 'credit', '500000.00'],
    ], 'Sale');

    $entry('2026-08-05', [
        ['6100', 'debit', '120000.00'],
        ['1020', 'credit', '120000.00'],
    ], 'Rent');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('serves the hub and every statement', function (): void {
    $this->get('/reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Reports/Index')->has('groups', 4));

    foreach ([
        '/reports/profit-and-loss',
        '/reports/balance-sheet',
        '/reports/cash-flow',
        '/reports/tax-summary',
        '/reports/analytics',
    ] as $path) {
        $this->get($path)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Reports/Statement')->has('report'));
    }
});

it('404s on a report that does not exist', function (): void {
    // The module has landed, so the placeholder no longer stands in for it.
    $this->get('/reports/something-else')->assertNotFound();
});

it('reports the profit, and says the balance sheet balances', function (): void {
    $this->get('/reports/profit-and-loss')
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.title', 'Profit and loss')
            ->where('report.footer.3.label', 'Profit for the period')
            ->where('report.footer.3.values.amount', '380000.0000'),
        );

    $this->get('/reports/balance-sheet')
        ->assertInertia(fn (Assert $page) => $page->where('report.reconciles', true));
});

it('defaults the tax summary to the quarter rather than the year', function (): void {
    // The period a return is usually filed for, so it is the one that needs
    // no changing for the common case.
    $this->get('/reports/tax-summary')
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.period.from', '2026-07-01')
            ->where('report.period.to', '2026-09-30'),
        );
});

it('takes an explicit span over a preset, and both bounds or neither', function (): void {
    $this->get('/reports/profit-and-loss?from=2026-07-01&to=2026-07-31')
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.period.from', '2026-07-01')
            ->where('report.period.to', '2026-07-31')
            // July only: the sale, and not August's rent.
            ->where('report.footer.3.values.amount', '500000.0000'),
        );

    // Half a range is a mistake, not an instruction — the preset stands.
    $this->get('/reports/profit-and-loss?from=2026-07-01')
        ->assertInertia(fn (Assert $page) => $page->where('report.period.from', '2026-07-01'))
        ->assertOk();
});

it('adds a comparison column when one is asked for', function (): void {
    $this->get('/reports/profit-and-loss?preset=this_quarter&compare=previous')
        ->assertInertia(fn (Assert $page) => $page
            ->has('report.comparison')
            ->where('report.columns.2.key', 'comparison')
            ->where('report.columns.3.key', 'change'),
        );
});

it('carries a drill-through on every account row', function (): void {
    /*
     * The exit criterion, at the HTTP layer: the row the screen renders has
     * to arrive with the filter that reproduces it, not with instructions to
     * the browser to assemble one.
     */
    $this->get('/reports/profit-and-loss')
        ->assertInertia(function (Assert $page): void {
            $sections = $page->toArray()['props']['report']['sections'];

            $row = $sections[0]['rows'][0];

            expect($row['drill'])->not->toBeNull()
                ->and($row['drill']['from'])->toBe('2026-07-01')
                ->and($row['drill']['to'])->toBe('2027-06-30');
        });

    // And the screen it points at answers.
    $this->get('/accounting/general-ledger?account='.ledgerAccount('4010')->id.'&from=2026-07-01&to=2026-09-30')
        ->assertOk();
});

describe('exports', function (): void {
    it('writes a CSV carrying the same figure as the screen', function (): void {
        $response = $this->get('/reports/profit-and-loss?format=csv');

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        expect($response->headers->get('content-disposition'))
            ->toContain('profit-and-loss-2027-06-30.csv');

        $csv = (string) $response->getContent();

        expect($csv)->toContain('Profit and loss')
            ->toContain('Profit for the period')
            // Unformatted: a thousands separator turns a number into text the
            // moment it reaches a spreadsheet.
            ->toContain('380000.0000')
            ->not->toContain('380,000');
    });

    it('writes a spreadsheet that opens as one, with the figure as a number', function (): void {
        $response = $this->get('/reports/profit-and-loss?format=xlsx');

        $response->assertOk()->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        $path = tempnam(sys_get_temp_dir(), 'test-') ?: '';
        file_put_contents($path, $response->getContent());

        $zip = new ZipArchive;

        expect($zip->open($path))->toBeTrue();

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $workbook = $zip->getFromName('xl/workbook.xml');

        $zip->close();
        unlink($path);

        expect($sheet)->toBeString()
            // A number in a <v> element, not an inline string: a figure that
            // arrives as text cannot be summed, and summing a column is the
            // first thing anybody does with an exported report.
            ->toContain('<v>380000.0000</v>')
            ->and($workbook)->toContain('Profit and loss');
    });

    it('renders a print document with repeating headers and no navigation', function (): void {
        $response = $this->get('/reports/balance-sheet?format=print');

        $response->assertOk();

        $html = $response->getContent();

        expect($html)->toContain('<h1>Balance sheet</h1>')
            ->toContain('display: table-header-group')
            ->toContain('@page')
            // The shell has no business on a printed statement.
            ->not->toContain('data-page=');
    });

    it('refuses to export to somebody who may only read', function (): void {
        /*
         * Reading a statement happens inside a session. A CSV leaves with
         * whoever asked for it, so it is a different permission — and a
         * bookkeeper, an approver and a viewer deliberately do not have it.
         */
        actingAsMember($this->organization, Role::Bookkeeper->value);

        $this->get('/reports/profit-and-loss')->assertOk();

        $this->get('/reports/profit-and-loss?format=csv')->assertForbidden();
        $this->get('/reports/profit-and-loss?format=xlsx')->assertForbidden();
        $this->get('/reports/balance-sheet?format=print')->assertForbidden();
    });

    it('lets an accountant export', function (): void {
        actingAsMember($this->organization, Role::Accountant->value);

        $this->get('/reports/profit-and-loss?format=csv')->assertOk();
    });
});

it('shows another organisation nothing of ours', function (): void {
    $other = Organization::factory()->create();

    actingAsMember($other, Role::Owner->value);

    // Their own books are empty, so their profit is zero — ours is not
    // visible to them at all.
    $this->get('/reports/profit-and-loss')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('report.footer.3.values.amount', '0.0000'));
});
