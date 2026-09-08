<?php

declare(strict_types=1);

use App\Domain\Access\Enums\Role;
use App\Domain\Accounting\Actions\PrepareLedger;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Organizations\Enums\MembershipStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

/*
|---------------------------------------------------------------------------
| The accounting journey, through a real browser
|---------------------------------------------------------------------------
|
| The HTTP tests already assert that each controller returns the right
| component with the right props. What they cannot see is whether the page
| renders at all, whether the running totals agree with the server before
| anything is submitted, and whether a posted entry is reachable from the
| screens meant to show it. A component that throws on mount still returns
| 200.
|
| Every phase of this project so far has had a bug that only a browser found.
| This suite exists so the accounting screens do not join that list.
|
| Selectors are CSS or field names, never visible copy for controls: a test
| that breaks when a label is reworded is a test people learn to ignore.
*/

const BROWSER_PASSWORD = 'a-long-enough-browser-password';

beforeEach(function (): void {
    $this->organization = Organization::factory()->create([
        'name' => 'Alpha Traders',
        'base_currency' => 'PKR',
        'fiscal_year_start_month' => 7,
    ]);

    $this->accountant = User::factory()->create([
        'name' => 'Nadia Accountant',
        'email' => 'nadia@alpha.test',
        'password' => Hash::make(BROWSER_PASSWORD),
        'email_verified_at' => now(),
    ]);

    OrganizationMembership::query()->create([
        'organization_id' => $this->organization->getKey(),
        'user_id' => $this->accountant->getKey(),
        'role' => Role::Accountant->value,
        'status' => MembershipStatus::Active,
        'joined_at' => now(),
    ]);

    $this->accountant->forceFill([
        'last_organization_id' => $this->organization->getKey(),
    ])->save();

    // A ledger to work in, built through the Action so the browser sees
    // exactly what an organisation has after onboarding.
    app(TenantContext::class)->runAs($this->organization, function (): void {
        app(PrepareLedger::class)->handle($this->organization, $this->accountant);

        $this->ar = Account::query()
            ->where('system_role', SystemAccount::AccountsReceivable->value)
            ->sole();

        $this->revenue = Account::query()
            ->where('type', 'income')
            ->where('is_header', false)
            ->whereNull('system_role')
            ->orderBy('code')
            ->firstOrFail();
    });
});

/**
 * Assert against the ledger, in the organisation's own context.
 *
 * The request that just ran established tenant context and restored what it
 * found on the way out — which is nothing, since a browser test sets none.
 * A scoped model therefore refuses an unconstrained query, and rightly: that
 * refusal is the guard that stops a forgotten `where` crossing tenants.
 */
function inLedger(Closure $assertions): void
{
    app(TenantContext::class)->runAs(test()->organization, $assertions);
}

/**
 * Sign in through the form, as a person would.
 *
 * Not `actingAs`: the session cookie, the CSRF token and the Inertia boot are
 * all part of what is being tested, and skipping them proves the pages work
 * for a request production never makes.
 */
function signIn(): mixed
{
    return visit('/login')
        ->fill('[name="email"]', 'nadia@alpha.test')
        ->fill('[name="password"]', BROWSER_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/dashboard');
}

it('renders every accounting screen without a JavaScript error', function (): void {
    $page = signIn();

    /*
     * A JavaScript error on any of these means the page rendered blank for a
     * real user. No HTTP assertion notices: the server returned 200 either
     * way.
     */
    $page->navigate('/accounting/accounts')
        ->assertSee('Chart of Accounts')
        ->assertSee($this->ar->code)
        ->assertNoJavaScriptErrors();

    $page->navigate('/accounting/journals')
        ->assertSee('Manual Journals')
        ->assertNoJavaScriptErrors();

    $page->navigate('/accounting/general-ledger')
        ->assertSee('General Ledger')
        ->assertNoJavaScriptErrors();

    $page->navigate('/accounting/trial-balance')
        ->assertSee('Trial Balance')
        // An empty ledger still balances, and the page has to say so rather
        // than leave the reader to infer it from two zeroes.
        ->assertSee('The books balance')
        ->assertNoJavaScriptErrors();

    $page->navigate('/accounting/periods')
        ->assertSee('Fiscal Periods')
        ->assertNoJavaScriptErrors();

    $page->navigate('/accounting/currencies')
        ->assertSee('Currencies')
        ->assertNoJavaScriptErrors();
});

it('names the difference on an unbalanced entry, before it can be submitted', function (): void {
    $page = signIn()->navigate('/accounting/journals/new');

    $page->assertSee('New journal entry')
        ->select('[name="lines.0.account_id"]', $this->ar->id)
        ->fill('[name="lines.0.amount"]', '1000.00')
        ->select('[name="lines.1.account_id"]', $this->revenue->id)
        ->fill('[name="lines.1.amount"]', '900.00');

    /*
     * The running total is the whole point of this form: it has to name the
     * difference while the user can still fix it cheaply, rather than after a
     * round trip.
     */
    $page->assertSee('Out by')
        ->assertNoJavaScriptErrors();

    inLedger(function (): void {
        expect(JournalEntry::query()->count())->toBe(0);
    });
});

it('posts a balanced journal and finds it on every screen that should show it', function (): void {
    $page = signIn()->navigate('/accounting/journals/new');

    $page->fill('[name="memo"]', 'Consulting fee, September')
        ->select('[name="lines.0.account_id"]', $this->ar->id)
        ->fill('[name="lines.0.amount"]', '50000.00')
        ->select('[name="lines.1.account_id"]', $this->revenue->id)
        ->fill('[name="lines.1.amount"]', '50000.00')
        ->assertSee('Balanced')
        // Posting confirms first, and the confirmation says what posting
        // means: the entry cannot afterwards be edited, only reversed.
        ->click('button[type="submit"]')
        ->assertSee('only reversed')
        ->click('button[type="submit"]')
        ->assertPathBeginsWith('/accounting/journals/JE-')
        ->assertSee('Consulting fee, September')
        ->assertNoJavaScriptErrors();

    $entryNo = null;

    inLedger(function () use (&$entryNo): void {
        $entry = JournalEntry::query()->sole();
        $entryNo = $entry->entry_no;

        expect($entry->entry_no)->toBe('JE-000001')
            ->and((string) $entry->total_debit)->toBe('50000.0000');
    });

    // The chart shows the balance it moved...
    $page->navigate('/accounting/accounts')
        ->assertSee('50,000.00');

    // ...the trial balance still balances...
    $page->navigate('/accounting/trial-balance')
        ->assertSee('The books balance')
        ->assertSee('50,000.00');

    // ...and the general ledger shows it against the account, opening balance
    // first.
    $page->navigate('/accounting/general-ledger?account='.$this->ar->id)
        ->assertSee((string) $entryNo)
        ->assertSee('Opening balance')
        ->assertNoJavaScriptErrors();
});

it('reverses a posted entry, leaving both visible and linked', function (): void {
    $page = signIn()->navigate('/accounting/journals/new');

    $page->select('[name="lines.0.account_id"]', $this->ar->id)
        ->fill('[name="lines.0.amount"]', '2500.00')
        ->select('[name="lines.1.account_id"]', $this->revenue->id)
        ->fill('[name="lines.1.amount"]', '2500.00')
        ->click('button[type="submit"]')
        ->click('button[type="submit"]')
        ->assertSee('JE-000001');

    $page->click('button:has-text("Reverse this entry")')
        ->fill('[name="reason"]', 'Billed the wrong customer')
        ->click('button[type="submit"]')
        ->assertSee('Billed the wrong customer')
        ->assertNoJavaScriptErrors();

    // Both remain, and each points at the other. That pairing is what an
    // auditor actually reads.
    $page->navigate('/accounting/journals')
        ->assertSee('JE-000001')
        ->assertSee('JE-000002')
        ->assertSee('Reversed');

    inLedger(function (): void {
        expect(JournalEntry::query()->count())->toBe(2);
    });
});

it('offers an accountant no closed-period override, because they have none', function (): void {
    // The permission belongs to owners and admins. The control must be absent
    // rather than present-and-refused: offering an action somebody cannot
    // take is a worse interface than not offering it.
    signIn()
        ->navigate('/accounting/journals/new')
        ->assertDontSee('Post even if the period is closed')
        ->assertMissing('[name="post_to_closed_period"]')
        ->assertNoJavaScriptErrors();
});

it('creates an account from the chart, and shows it in the right group', function (): void {
    $page = signIn()->navigate('/accounting/accounts');

    $page->click('button:has-text("New account")')
        ->fill('[name="code"]', '1215')
        ->fill('[name="name"]', 'Trade Receivables — Retail')
        ->select('[name="type"]', 'asset')
        ->click('button[type="submit"]')
        ->assertSee('1215')
        ->assertSee('Trade Receivables — Retail')
        ->assertNoJavaScriptErrors();

    inLedger(function (): void {
        expect(Account::query()->where('code', '1215')->exists())->toBeTrue();
    });
});

it('holds up on a narrow screen', function (): void {
    /*
     * The 390px check from the UI gate in CLAUDE.md. A wide table has to
     * scroll inside its own container — if the page body scrolls sideways
     * instead, the navigation and the primary action go with it.
     */
    visit('/login')
        ->on()->mobile()
        ->fill('[name="email"]', 'nadia@alpha.test')
        ->fill('[name="password"]', BROWSER_PASSWORD)
        ->click('button[type="submit"]')
        ->navigate('/accounting/trial-balance')
        ->assertSee('Trial Balance')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});
