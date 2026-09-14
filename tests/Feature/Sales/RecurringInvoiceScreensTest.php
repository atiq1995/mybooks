<?php

declare(strict_types=1);

use App\Domain\Access\Enums\Role;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contacts\Enums\ContactKind;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Sales\Actions\SaveRecurringInvoice;
use App\Domain\Sales\Models\RecurringInvoice;
use App\Domain\Sales\Models\SalesDocument;
use App\Domain\Tax\Models\Tax;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|---------------------------------------------------------------------------
| The recurring-invoice screens
|---------------------------------------------------------------------------
|
| The domain tests prove the schedule arithmetic and the idempotency. These
| cover the HTTP layer: that generating needs the permission to SEND a
| document rather than merely to edit a template, that a template from another
| organisation is a 404, and that the screens keep the template/document
| distinction visible.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-20');

    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->owner = actingAsMember($this->organization, Role::Owner->value);

    $this->gst = Tax::query()->where('code', 'GST18')->sole();
    $this->revenue = Account::query()->where('code', '4010')->sole();

    $this->customer = Contact::query()->create([
        'kind' => ContactKind::Customer,
        'display_name' => 'Karachi Textiles',
        'payment_terms_days' => 30,
    ]);

    $this->payload = fn (array $overrides = []): array => [
        'name' => 'Karachi Textiles — retainer',
        'contact_id' => $this->customer->id,
        'frequency' => 'monthly',
        'interval' => 1,
        'starts_on' => '2026-09-01',
        'auto_issue' => true,
        'lines' => [[
            'description' => 'Monthly retainer',
            'quantity' => '1',
            'unit_price' => '100000.00',
            'tax_id' => $this->gst->id,
            'revenue_account_id' => $this->revenue->id,
        ]],
        ...$overrides,
    ];
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('serves the list', function (): void {
    $this->get('/sales/recurring-invoices')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Sales/Recurring/Index'));
});

it('creates a template that is not an invoice', function (): void {
    $this->post('/sales/recurring-invoices', ($this->payload)())
        ->assertRedirect('/sales/recurring-invoices');

    $template = RecurringInvoice::query()->sole();

    expect($template->status)->toBe('active')
        ->and($template->next_run_on?->toDateString())->toBe('2026-09-01')
        // Nothing billed yet, and nothing in the ledger.
        ->and(SalesDocument::query()->count())->toBe(0);
});

it('refuses a line pointing at an expense account', function (): void {
    $expense = Account::query()->postable()->where('type', 'expense')->firstOrFail();

    $payload = ($this->payload)();
    $payload['lines'][0]['revenue_account_id'] = $expense->id;

    $this->post('/sales/recurring-invoices', $payload)
        ->assertSessionHasErrors('lines.0.revenue_account_id');
});

it('refuses a schedule that ends before it starts', function (): void {
    $this->post('/sales/recurring-invoices', ($this->payload)(['ends_on' => '2026-08-01']))
        ->assertSessionHasErrors('ends_on');
});

it('runs one now, and says so when nothing is due', function (): void {
    // Starts 1 September, and "today" is the 20th, so one is owed.
    $this->post('/sales/recurring-invoices', ($this->payload)());

    $template = RecurringInvoice::query()->sole();

    $this->post("/sales/recurring-invoices/{$template->id}/generate")
        ->assertSessionHas('success');

    $invoice = SalesDocument::query()->sole();

    expect($invoice->issue_date->toDateString())->toBe('2026-09-01')
        ->and($invoice->total)->toBeDecimal('118000.0000');

    // The next one is October, so pressing again does nothing.
    $response = $this->post("/sales/recurring-invoices/{$template->id}/generate");

    $response->assertSessionHas('success');

    expect(session('success'))->toContain('Nothing due yet')
        ->and(SalesDocument::query()->count())->toBe(1);
});

it('shows the history, including a failure', function (): void {
    $this->post('/sales/recurring-invoices', ($this->payload)());

    $template = RecurringInvoice::query()->sole();

    $this->post("/sales/recurring-invoices/{$template->id}/generate");

    $this->get("/sales/recurring-invoices/{$template->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sales/Recurring/Show')
            ->where('template.occurrences_generated', 1)
            ->where('template.runs.0.outcome', 'generated')
            ->where('template.runs.0.scheduled_for', '2026-09-01'),
        );

    /*
     * Archive the customer, then try October. The failure is recorded and
     * shown — hiding it would leave a customer unbilled with no sign of why.
     */
    $this->customer->forceFill(['archived_at' => now(), 'is_active' => false])->save();

    Carbon::setTestNow('2026-10-05');

    $this->post("/sales/recurring-invoices/{$template->id}/generate")
        ->assertSessionHas('error');

    $this->get("/sales/recurring-invoices/{$template->id}")
        ->assertInertia(function (Assert $page): void {
            $runs = $page->toArray()['props']['template']['runs'];

            $failed = collect($runs)->firstWhere('outcome', 'failed');

            expect($failed)->not->toBeNull();
            expect($failed['failure_reason'])->toContain('archived');
        });
});

it('pauses and resumes from the list', function (): void {
    $this->post('/sales/recurring-invoices', ($this->payload)());

    $template = RecurringInvoice::query()->sole();

    $this->post("/sales/recurring-invoices/{$template->id}/status", ['status' => 'paused'])
        ->assertSessionHas('success');

    expect($template->fresh()?->status)->toBe('paused')
        // Its place is kept, which is the whole point of pausing.
        ->and($template->fresh()?->next_run_on?->toDateString())->toBe('2026-09-01');

    $this->post("/sales/recurring-invoices/{$template->id}/status", ['status' => 'active'])
        ->assertSessionHas('success');

    expect($template->fresh()?->status)->toBe('active');
});

it('deletes the template and leaves its invoices alone', function (): void {
    $this->post('/sales/recurring-invoices', ($this->payload)());

    $template = RecurringInvoice::query()->sole();

    $this->post("/sales/recurring-invoices/{$template->id}/generate");

    $this->delete("/sales/recurring-invoices/{$template->id}")
        ->assertRedirect('/sales/recurring-invoices');

    /*
     * The invoice it produced is an ordinary document with its own number and
     * its own journal entry — deleting a standing instruction cannot unpost
     * what it already billed.
     */
    expect(RecurringInvoice::query()->count())->toBe(0)
        ->and(SalesDocument::query()->count())->toBe(1)
        ->and(SalesDocument::query()->sole()->journal_entry_id)->not->toBeNull();
});

it('404s on a template from another organisation', function (): void {
    $other = Organization::factory()->create();

    $theirs = app(TenantContext::class)->runAs($other, function () use ($other): RecurringInvoice {
        withLedger($other);

        $customer = Contact::query()->create([
            'kind' => ContactKind::Customer,
            'display_name' => 'Theirs',
            'payment_terms_days' => 30,
        ]);

        return app(SaveRecurringInvoice::class)->handle(
            attributes: [
                'contact_id' => $customer->id,
                'frequency' => 'monthly',
                'starts_on' => '2026-09-01',
            ],
            lines: [[
                'description' => 'Theirs',
                'quantity' => '1',
                'unit_price' => '100.00',
                'revenue_account_id' => Account::query()->where('code', '4010')->sole()->id,
            ]],
        );
    });

    actingAsMember($this->organization, Role::Owner->value);

    $this->get("/sales/recurring-invoices/{$theirs->id}")->assertNotFound();
    $this->post("/sales/recurring-invoices/{$theirs->id}/generate")->assertNotFound();
});

describe('authorisation, by role', function (): void {
    it('lets a viewer read and change nothing', function (): void {
        $this->post('/sales/recurring-invoices', ($this->payload)());

        actingAsMember($this->organization, Role::Viewer->value);

        $this->get('/sales/recurring-invoices')->assertOk();

        $this->get('/sales/recurring-invoices/new')->assertForbidden();
        $this->post('/sales/recurring-invoices', ($this->payload)())->assertForbidden();

        expect(RecurringInvoice::query()->count())->toBe(1);
    });

    it('lets a bookkeeper set one up but not run one that posts', function (): void {
        /*
         * The separation of duties, and the loophole it would otherwise have.
         *
         * A Bookkeeper has `sales.send` — so requiring only that would let
         * somebody whose entire definition is "prepares documents, cannot
         * post" post a year of revenue by pressing Run now. An auto-issuing
         * template therefore needs `accounting.post` too, exactly as pressing
         * Issue on a single invoice does.
         */
        actingAsMember($this->organization, Role::Bookkeeper->value);

        $this->post('/sales/recurring-invoices', ($this->payload)())
            ->assertSessionHasNoErrors();

        $template = RecurringInvoice::query()->sole();

        $this->post("/sales/recurring-invoices/{$template->id}/generate")->assertForbidden();

        expect(SalesDocument::query()->count())->toBe(0);

        // And the button is absent rather than present-and-refused.
        $this->get("/sales/recurring-invoices/{$template->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can.generate', false));
    });

    it('lets a bookkeeper run a template that only produces drafts', function (): void {
        // Nothing is recognised, so no posting permission is called for.
        actingAsMember($this->organization, Role::Bookkeeper->value);

        $this->post('/sales/recurring-invoices', ($this->payload)(['auto_issue' => false]));

        $template = RecurringInvoice::query()->sole();

        $this->post("/sales/recurring-invoices/{$template->id}/generate")
            ->assertSessionHas('success');

        expect(SalesDocument::query()->sole()->journal_entry_id)->toBeNull();
    });
});
