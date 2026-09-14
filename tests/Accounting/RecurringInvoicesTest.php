<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contacts\Enums\ContactKind;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Sales\Actions\GenerateRecurringInvoices;
use App\Domain\Sales\Actions\SaveRecurringInvoice;
use App\Domain\Sales\Enums\SalesDocumentStatus;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Domain\Sales\Exceptions\SalesDocumentRefused;
use App\Domain\Sales\Models\RecurringInvoice;
use App\Domain\Sales\Models\RecurringInvoiceRun;
use App\Domain\Sales\Models\SalesDocument;
use App\Domain\Tax\Models\Tax;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|---------------------------------------------------------------------------
| Recurring invoices
|---------------------------------------------------------------------------
|
| The last Phase 3 deferral. §6: a recurring invoice posts "on each generated
| invoice, at its own date".
|
| Three properties matter more than the rest, and each gets its own test
| against the alternative:
|
|   It cannot double-bill, whatever the scheduler does.
|   It catches up rather than skipping, so an invoice is never silently lost.
|   Each invoice is dated on its own occurrence, so the tax rates, the due
|   date and the ageing are all right.
|
| @see ACCOUNTING_RULES.md §6, §9
*/

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->actor = User::factory()->create();

    $this->save = app(SaveRecurringInvoice::class);
    $this->generate = app(GenerateRecurringInvoices::class);

    $this->gst = Tax::query()->where('code', 'GST18')->sole();
    $this->revenue = Account::query()->where('code', '4010')->sole();
    $this->ar = ledgerAccount('1200');

    $this->customer = Contact::query()->create([
        'kind' => ContactKind::Customer,
        'display_name' => 'Karachi Textiles',
        'payment_terms_days' => 30,
    ]);

    /** A monthly retainer, starting in the fiscal year. */
    $this->template = fn (
        string $frequency = 'monthly',
        string $startsOn = '2026-07-01',
        int $interval = 1,
        bool $autoIssue = true,
        ?string $endsOn = null,
        ?int $maxOccurrences = null,
    ): RecurringInvoice => $this->save->handle(
        attributes: [
            'name' => 'Karachi Textiles — retainer',
            'contact_id' => $this->customer->id,
            'frequency' => $frequency,
            'interval' => $interval,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'max_occurrences' => $maxOccurrences,
            'auto_issue' => $autoIssue,
        ],
        lines: [[
            'description' => 'Monthly retainer',
            'quantity' => '1',
            'unit_price' => '100000.00',
            'tax_id' => $this->gst->id,
            'revenue_account_id' => $this->revenue->id,
        ]],
        actor: $this->actor,
    );
});

describe('a template', function (): void {
    it('is not an invoice: no number, no total, and it never posts', function (): void {
        $template = ($this->template)();

        expect($template->status)->toBe('active')
            ->and($template->next_run_on?->toDateString())->toBe('2026-07-01')
            ->and($template->occurrences_generated)->toBe(0);

        // Nothing at all in the sales tables or the ledger yet.
        expect(SalesDocument::query()->count())->toBe(0)
            ->and(JournalEntry::query()->count())->toBe(0);
    });

    it('takes its payment terms from the customer', function (): void {
        expect(($this->template)()->payment_terms_days)->toBe(30);
    });

    it('refuses a contact who is not a customer', function (): void {
        $vendor = Contact::query()->create([
            'kind' => ContactKind::Vendor,
            'display_name' => 'Supplier',
        ]);

        expect(fn () => $this->save->handle(
            attributes: [
                'contact_id' => $vendor->id,
                'frequency' => 'monthly',
                'starts_on' => '2026-07-01',
            ],
            lines: [[
                'description' => 'Something',
                'quantity' => '1',
                'unit_price' => '100.00',
                'revenue_account_id' => $this->revenue->id,
            ]],
            actor: $this->actor,
        ))->toThrow(SalesDocumentRefused::class, 'not marked as a customer');
    });
});

describe('generating', function (): void {
    it('produces an invoice dated on the occurrence, not on the run', function (): void {
        /*
         * §6's "at its own date", and the reason it matters: the tax rates
         * that apply are the ones in force then, the due date runs from then,
         * and the ageing is right.
         */
        ($this->template)();

        $this->generate->handle(Carbon::parse('2026-07-05'), $this->actor);

        $invoice = SalesDocument::query()->sole();

        expect($invoice->issue_date->toDateString())->toBe('2026-07-01')
            ->and($invoice->due_date?->toDateString())->toBe('2026-07-31')
            ->and($invoice->status)->toBe(SalesDocumentStatus::Sent)
            ->and($invoice->total)->toBeDecimal('118000.0000');

        // And it posted at its own date too.
        expect($invoice->journalEntry()->sole()->entry_date->toDateString())
            ->toBe('2026-07-01');
    });

    it('leaves a draft where the template says not to issue', function (): void {
        /*
         * Setting up the template IS the authorisation to issue, given in
         * advance. A business that would rather check each one turns it off.
         */
        ($this->template)(autoIssue: false);

        $this->generate->handle(Carbon::parse('2026-07-01'), $this->actor);

        $invoice = SalesDocument::query()->sole();

        expect($invoice->status)->toBe(SalesDocumentStatus::Draft)
            ->and($invoice->journal_entry_id)->toBeNull();
    });

    it('cannot bill the same occurrence twice, however many times it runs', function (): void {
        /*
         * The property that matters most. A scheduler firing twice, a worker
         * retried after a timeout and somebody pressing "generate now"
         * mid-run are three different races — and the unique index on
         * (template, scheduled date) is the only thing that can arbitrate
         * between processes.
         */
        ($this->template)();

        $this->generate->handle(Carbon::parse('2026-07-01'), $this->actor);
        $this->generate->handle(Carbon::parse('2026-07-01'), $this->actor);
        $this->generate->handle(Carbon::parse('2026-07-01'), $this->actor);

        expect(SalesDocument::query()->count())->toBe(1)
            ->and(RecurringInvoiceRun::query()->count())->toBe(1);
    });

    it('catches up rather than skipping, one invoice per missed period', function (): void {
        /*
         * A template due on 1 July, not run until October — the server was
         * down, or nobody set the scheduler up until Friday. Three
         * occurrences are owed, and all three are generated, each on its own
         * date. Skipping to "now" would silently drop invoices a business is
         * owed, which is the worst failure available here.
         */
        ($this->template)();

        $result = $this->generate->handle(Carbon::parse('2026-10-05'), $this->actor);

        expect($result['generated'])->toBe(4);

        $dates = SalesDocument::query()
            ->orderBy('issue_date')
            ->pluck('issue_date')
            ->map(static fn ($date): string => Carbon::parse((string) $date)->toDateString())
            ->all();

        expect($dates)->toBe(['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01']);
    });

    it('advances from the due date, so a late run does not shift the schedule', function (): void {
        /*
         * Advancing from "now" would make a monthly invoice run three days
         * late bill on the 4th for ever after. The next run is computed from
         * the occurrence, not from the clock.
         */
        ($this->template)();

        $this->generate->handle(Carbon::parse('2026-07-09'), $this->actor);

        expect(RecurringInvoice::query()->sole()->next_run_on?->toDateString())
            ->toBe('2026-08-01');
    });

    it('keeps the day of the month across a short February', function (): void {
        /*
         * A retainer billed on the 31st. Carbon's plain addMonths would roll
         * 31 January to 3 March, and the schedule would then sit on the 3rd
         * for ever. Clamping to the last day of the shorter month preserves
         * the intended day.
         */
        ($this->template)(startsOn: '2027-01-31');

        $this->generate->handle(Carbon::parse('2027-03-31'), $this->actor);

        $dates = SalesDocument::query()
            ->orderBy('issue_date')
            ->pluck('issue_date')
            ->map(static fn ($date): string => Carbon::parse((string) $date)->toDateString())
            ->all();

        expect($dates)->toBe(['2027-01-31', '2027-02-28', '2027-03-31']);
    });

    it('handles an interval of more than one', function (): void {
        ($this->template)(interval: 3);

        $this->generate->handle(Carbon::parse('2027-01-05'), $this->actor);

        $dates = SalesDocument::query()
            ->orderBy('issue_date')
            ->pluck('issue_date')
            ->map(static fn ($date): string => Carbon::parse((string) $date)->toDateString())
            ->all();

        expect($dates)->toBe(['2026-07-01', '2026-10-01', '2027-01-01']);
    });

    it('generates nothing before the start date', function (): void {
        ($this->template)(startsOn: '2026-09-01');

        $result = $this->generate->handle(Carbon::parse('2026-08-31'), $this->actor);

        expect($result['generated'])->toBe(0)
            ->and(SalesDocument::query()->count())->toBe(0);
    });
});

describe('ending', function (): void {
    it('stops after the agreed number of invoices', function (): void {
        ($this->template)(maxOccurrences: 3);

        $this->generate->handle(Carbon::parse('2027-06-01'), $this->actor);

        $template = RecurringInvoice::query()->sole();

        expect(SalesDocument::query()->count())->toBe(3)
            ->and($template->status)->toBe('ended')
            // The constraint ties these together: an ended schedule has no
            // next run, so it cannot quietly resume.
            ->and($template->next_run_on)->toBeNull();
    });

    it('stops after the agreed end date', function (): void {
        ($this->template)(endsOn: '2026-09-30');

        $this->generate->handle(Carbon::parse('2027-01-01'), $this->actor);

        expect(SalesDocument::query()->count())->toBe(3)
            ->and(RecurringInvoice::query()->sole()->status)->toBe('ended');
    });

    it('pauses and resumes, billing the periods it was paused for', function (): void {
        /*
         * A paused retainer that resumes still owes the months it covered —
         * the pause was about not sending invoices, not about the work
         * stopping. So `next_run_on` is kept, and the catch-up bills them.
         */
        $template = ($this->template)();

        $this->generate->handle(Carbon::parse('2026-07-01'), $this->actor);

        $this->save->setStatus($template->refresh(), 'paused', $this->actor);

        $this->generate->handle(Carbon::parse('2026-09-01'), $this->actor);

        expect(SalesDocument::query()->count())->toBe(1);

        $this->save->setStatus($template->refresh(), 'active', $this->actor);

        $this->generate->handle(Carbon::parse('2026-09-01'), $this->actor);

        expect(SalesDocument::query()->count())->toBe(3);
    });

    it('refuses to resume a schedule that has run to its end', function (): void {
        $template = ($this->template)(maxOccurrences: 1);

        $this->generate->handle(Carbon::parse('2026-07-01'), $this->actor);

        expect(fn () => $this->save->setStatus($template->refresh(), 'active', $this->actor))
            ->toThrow(InvalidArgumentException::class, 'nothing left to resume');
    });
});

describe('when an occurrence cannot be produced', function (): void {
    it('records the failure and stops that template rather than retrying for ever', function (): void {
        /*
         * The customer is archived between runs. Every later occurrence would
         * fail identically, so the schedule stops where it stopped — keeping
         * its place — and somebody is told once instead of a thousand times.
         */
        ($this->template)();

        $this->customer->forceFill(['archived_at' => now(), 'is_active' => false])->save();

        $result = $this->generate->handle(Carbon::parse('2026-10-01'), $this->actor);

        expect($result['generated'])->toBe(0)
            ->and($result['failed'])->toBe(1)
            ->and(SalesDocument::query()->count())->toBe(0);

        $run = RecurringInvoiceRun::query()->sole();

        expect($run->outcome)->toBe('failed')
            ->and($run->failure_reason)->toContain('archived')
            ->and($run->scheduled_for->toDateString())->toBe('2026-07-01');

        // And the schedule kept its place, so fixing the cause and running
        // again picks up exactly where it left off.
        expect(RecurringInvoice::query()->sole()->next_run_on?->toDateString())
            ->toBe('2026-07-01');
    });
});

describe('the whole chain', function (): void {
    it('leaves the ledger verifiable after a year of monthly invoices', function (): void {
        ($this->template)(maxOccurrences: 12);

        $this->generate->handle(Carbon::parse('2027-06-30'), $this->actor);

        expect(SalesDocument::query()->ofType(SalesDocumentType::Invoice)->count())->toBe(12);

        $this->artisan('my-books:verify-ledger', [
            '--organization' => $this->organization->slug,
        ])->assertExitCode(0);

        // Twelve invoices at 118,000 each.
        expect($this->ar->balance())->toBeDecimal('1416000.0000')
            ->and($this->customer->outstandingBalance())->toBeDecimal('1416000.0000');
    });
});
