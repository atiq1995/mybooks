<?php

declare(strict_types=1);

use App\Domain\Access\Enums\Role;
use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|---------------------------------------------------------------------------
| Organisation settings
|---------------------------------------------------------------------------
|
| The Phase 1 leftover: creation existed, editing did not, so a typo in a
| company's legal name was permanent.
|
| Most of what is asserted here is about what CANNOT be changed. The base
| currency is not writable at all — not validated-and-rejected, absent from
| the rules, so no shape of request reaches it. The financial year locks the
| moment something posts, and the refusal says why rather than dropping the
| field silently.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-20');

    $this->organization = Organization::factory()->create([
        'name' => 'Malik Textiles',
        'base_currency' => 'PKR',
        'fiscal_year_start_month' => 7,
    ]);

    $this->owner = actingAsMember($this->organization, Role::Owner->value);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('serves the screen', function (): void {
    $this->get('/settings/organization')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Organization')
            ->where('organization.name', 'Malik Textiles')
            ->where('organization.base_currency', 'PKR'),
        );
});

it('updates the details a company can change', function (): void {
    $this->patch('/settings/organization', [
        'name' => 'Malik Textiles Limited',
        'legal_name' => 'Malik Textiles (Private) Limited',
        'country_code' => 'PK',
        'rounding_mode' => 'HALF_UP',
        'tax_registration_number' => '1234567-8',
        'timezone' => 'Asia/Karachi',
        'locale' => 'en',
        'date_format' => 'd M Y',
        'address' => ['line1' => '12 Mill Road', 'city' => 'Karachi'],
        'email' => 'accounts@maliktextiles.test',
    ])->assertSessionHas('success');

    $organization = $this->organization->fresh();

    expect($organization?->name)->toBe('Malik Textiles Limited')
        ->and($organization?->legal_name)->toBe('Malik Textiles (Private) Limited')
        ->and($organization?->tax_registration_number)->toBe('1234567-8')
        ->and($organization?->address['city'] ?? null)->toBe('Karachi');
});

it('never lets the base currency be changed, however the request is shaped', function (): void {
    /*
     * The strongest guard available is not being writable.
     *
     * Every posted amount is stored converted to this currency, so changing
     * it would not restate those figures — it would reinterpret them, and a
     * year of accounts would quietly mean something else.
     */
    $this->patch('/settings/organization', [
        'name' => 'Malik Textiles',
        'country_code' => 'PK',
        'rounding_mode' => 'HALF_UP',
        'timezone' => 'Asia/Karachi',
        'locale' => 'en',
        'date_format' => 'd M Y',
        'base_currency' => 'USD',
    ])->assertSessionHas('success');

    // Accepted as a request, ignored as a field.
    expect($this->organization->fresh()?->base_currency)->toBe('PKR');
});

it('says the base currency is locked, and why', function (): void {
    $this->get('/settings/organization')
        ->assertInertia(function (Assert $page): void {
            $page->where('locked.base_currency.locked', true);

            expect($page->toArray()['props']['locked']['base_currency']['reason'])
                ->toContain('reinterpret');
        });
});

describe('the financial year', function (): void {
    it('can be moved while nothing has posted', function (): void {
        $this->get('/settings/organization')
            ->assertInertia(fn (Assert $page) => $page
                ->where('locked.fiscal_year_start_month.locked', false),
            );

        $this->patch('/settings/organization', [
            'name' => 'Malik Textiles',
            'country_code' => 'PK',
            'rounding_mode' => 'HALF_UP',
            'timezone' => 'Asia/Karachi',
            'locale' => 'en',
            'date_format' => 'd M Y',
            'fiscal_year_start_month' => 4,
        ])->assertSessionHas('success');

        expect($this->organization->fresh()?->fiscal_year_start_month)->toBe(4);
    });

    it('locks the moment something posts, with a reason', function (): void {
        postSomething();

        $this->get('/settings/organization')
            ->assertInertia(function (Assert $page): void {
                $page->where('locked.fiscal_year_start_month.locked', true);

                expect($page->toArray()['props']['locked']['fiscal_year_start_month']['reason'])
                    ->toContain('comparatives');
            });

        /*
         * Refused rather than ignored. Silently dropping a field somebody
         * filled in is the behaviour that makes people distrust a form — so
         * the error names the reason.
         */
        $response = $this->patch('/settings/organization', [
            'name' => 'Malik Textiles',
            'country_code' => 'PK',
            'rounding_mode' => 'HALF_UP',
            'timezone' => 'Asia/Karachi',
            'locale' => 'en',
            'date_format' => 'd M Y',
            'fiscal_year_start_month' => 4,
        ]);

        $response->assertSessionHasErrors('fiscal_year_start_month');

        expect(session('errors')?->first('fiscal_year_start_month'))
            ->toContain('cannot be moved once something has been posted');

        expect($this->organization->fresh()?->fiscal_year_start_month)->toBe(7);
    });
});

it('refuses an invalid timezone and a malformed website', function (): void {
    $this->patch('/settings/organization', [
        'name' => 'Malik Textiles',
        'country_code' => 'PK',
        'rounding_mode' => 'HALF_UP',
        'timezone' => 'Mars/Olympus',
        'locale' => 'en',
        'date_format' => 'd M Y',
        'website' => 'not a url',
    ])->assertSessionHasErrors(['timezone', 'website']);
});

it('404s nothing across organisations — it only ever edits the active one', function (): void {
    $other = Organization::factory()->create(['name' => 'Somebody Else']);

    app(TenantContext::class)->runAs($other, function () use ($other): void {
        withLedger($other);
    });

    $this->patch('/settings/organization', [
        'name' => 'Renamed From Elsewhere',
        'country_code' => 'PK',
        'rounding_mode' => 'HALF_UP',
        'timezone' => 'Asia/Karachi',
        'locale' => 'en',
        'date_format' => 'd M Y',
    ])->assertSessionHas('success');

    // The active organisation changed; the other one is untouched. There is
    // no id in the request at all, which is what makes that certain.
    expect($this->organization->fresh()?->name)->toBe('Renamed From Elsewhere')
        ->and($other->fresh()?->name)->toBe('Somebody Else');
});

describe('authorisation', function (): void {
    it('lets a viewer read it and change nothing', function (): void {
        actingAsMember($this->organization, Role::Viewer->value);

        $this->get('/settings/organization')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.update', false));

        $this->patch('/settings/organization', [
            'name' => 'Should not stick',
            'country_code' => 'PK',
            'rounding_mode' => 'HALF_UP',
            'timezone' => 'Asia/Karachi',
            'locale' => 'en',
            'date_format' => 'd M Y',
        ])->assertForbidden();

        expect($this->organization->fresh()?->name)->toBe('Malik Textiles');
    });

    it('needs the organisation permission, which an accountant lacks', function (): void {
        /*
         * An accountant runs the books; the company's legal name and tax
         * numbers are a different kind of decision, and they appear on every
         * document it issues.
         */
        actingAsMember($this->organization, Role::Accountant->value);

        $this->get('/settings/organization')->assertOk();

        $this->patch('/settings/organization', [
            'name' => 'Should not stick',
            'country_code' => 'PK',
            'rounding_mode' => 'HALF_UP',
            'timezone' => 'Asia/Karachi',
            'locale' => 'en',
            'date_format' => 'd M Y',
        ])->assertForbidden();
    });
});

/**
 * Put one entry in the ledger, so "something has posted" is true.
 */
function postSomething(): void
{
    test()->year = withLedger(test()->organization, 2026);

    app(PostJournalEntry::class)->handle(
        JournalDraft::inBaseCurrency(
            date: Carbon::parse('2026-09-15'),
            currency: 'PKR',
            lines: [
                JournalLineDraft::debit(ledgerAccount('1020')->id, '1000.0000'),
                JournalLineDraft::credit(
                    ledgerAccount(SystemAccount::OpeningBalanceEquity)->id,
                    '1000.0000',
                ),
            ],
            source: ['manual', null, 'issue'],
        ),
        test()->owner,
    );
}
