<?php

declare(strict_types=1);

use App\Domain\Access\AccessControl;
use App\Domain\Access\Enums\Permission;
use App\Domain\Access\Enums\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;

function validOrganizationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Alpha Traders',
        'legal_name' => 'Alpha Traders (Private) Limited',
        'country_code' => 'PK',
        'base_currency' => 'PKR',
        'fiscal_year_start_month' => 7,
        'timezone' => 'Asia/Karachi',
    ], $overrides);
}

it('shows the creation form with the reference data it needs', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/organizations/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Organizations/Create')
            ->has('options.countries')
            ->has('options.currencies')
            ->has('options.months', 12)
            ->where('options.defaults.base_currency', 'PKR'),
        );
});

it('creates the organisation and makes the creator its owner', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post('/organizations', validOrganizationPayload())
        ->assertRedirect('/onboarding');

    $organization = app(TenantContext::class)->runUnscoped(
        fn () => Organization::query()->where('name', 'Alpha Traders')->firstOrFail(),
    );

    expect($organization->base_currency)->toBe('PKR')
        ->and($organization->country_code)->toBe('PK')
        ->and($organization->fiscal_year_start_month)->toBe(7)
        ->and($organization->created_by)->toBe($user->getKey())
        // Not usable until the wizard finishes.
        ->and($organization->hasCompletedOnboarding())->toBeFalse();

    $membership = $organization->memberships()->where('user_id', $user->getKey())->first();

    expect($membership?->role)->toBe(Role::Owner->value)
        ->and($membership?->isActive())->toBeTrue();
});

it('makes the new organisation active for the session', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post('/organizations', validOrganizationPayload());

    $organization = app(TenantContext::class)->runUnscoped(
        fn () => Organization::query()->where('name', 'Alpha Traders')->firstOrFail(),
    );

    $this->assertEquals($organization->getKey(), session('active_organization_id'));
    expect($user->fresh()?->last_organization_id)->toBe($organization->getKey());
});

it('gives the owner full permissions in the organisation it just created', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post('/organizations', validOrganizationPayload());

    $organization = app(TenantContext::class)->runUnscoped(
        fn () => Organization::query()->where('name', 'Alpha Traders')->firstOrFail(),
    );

    /*
     * Asked of AccessControl directly rather than through the Gate. The Gate
     * resolves against whichever organisation is active in the CURRENT
     * request, and by this point the request that created it has ended and
     * correctly released its context.
     */
    $access = app(AccessControl::class);

    expect($access->allows($user, $organization, Permission::AccountingPost))->toBeTrue()
        ->and($access->allows($user, $organization, Permission::OrganizationArchive))->toBeTrue()
        ->and($access->roleFor($user, $organization))->toBe(Role::Owner);
});

it('leaves no tenant context behind once the request has ended', function (): void {
    $this->actingAs(User::factory()->create());

    $this->post('/organizations', validOrganizationPayload());

    // The organisation was made active DURING the request; it must not still
    // be active afterwards, or a queued job or console command running in the
    // same process would inherit it.
    expect(app(TenantContext::class)->has())->toBeFalse();
});

it('audits the creation', function (): void {
    $user = User::factory()->create(['name' => 'Ayesha Khan']);
    $this->actingAs($user);

    $this->post('/organizations', validOrganizationPayload());

    $entry = app(TenantContext::class)->runUnscoped(
        fn () => AuditLog::query()->where('action', 'organization.created')->first(),
    );

    expect($entry)->not->toBeNull()
        ->and($entry?->actor_name)->toBe('Ayesha Khan')
        ->and($entry?->new_values)->toHaveKey('base_currency', 'PKR');
});

it('derives a readable slug, and keeps it unique across name collisions', function (): void {
    $this->actingAs(User::factory()->create());
    $this->post('/organizations', validOrganizationPayload());

    $this->actingAs(User::factory()->create());
    $this->post('/organizations', validOrganizationPayload());

    $slugs = app(TenantContext::class)->runUnscoped(
        fn () => Organization::query()->where('name', 'Alpha Traders')->pluck('slug')->all(),
    );

    expect($slugs)->toHaveCount(2)
        ->and($slugs[0])->toBe('alpha-traders')
        // Second gets a random suffix rather than "-2", which would leak how
        // many similarly-named organisations exist.
        ->and($slugs[1])->toStartWith('alpha-traders-')
        ->and($slugs[1])->not->toBe($slugs[0]);
});

it('rejects an unsupported base currency', function (): void {
    $this->actingAs(User::factory()->create());

    $this->post('/organizations', validOrganizationPayload(['base_currency' => 'XYZ']))
        ->assertSessionHasErrors('base_currency');

    expect(app(TenantContext::class)->runUnscoped(fn () => Organization::query()->count()))->toBe(0);
});

it('rejects a fiscal month outside the year', function (): void {
    $this->actingAs(User::factory()->create());

    $this->post('/organizations', validOrganizationPayload(['fiscal_year_start_month' => 13]))
        ->assertSessionHasErrors('fiscal_year_start_month');
});

it('requires a name', function (): void {
    $this->actingAs(User::factory()->create());

    $this->post('/organizations', validOrganizationPayload(['name' => '']))
        ->assertSessionHasErrors('name');
});

it('refuses creation by an unverified account', function (): void {
    $this->actingAs(User::factory()->unverified()->create());

    // The `verified` middleware turns this away before the form request runs.
    $this->post('/organizations', validOrganizationPayload())->assertRedirect();

    expect(app(TenantContext::class)->runUnscoped(fn () => Organization::query()->count()))->toBe(0);
});

it('refuses creation by a suspended account', function (): void {
    $this->actingAs(User::factory()->suspended()->create());

    $this->post('/organizations', validOrganizationPayload())->assertForbidden();
});

it('turns guests away', function (): void {
    $this->get('/organizations/create')->assertRedirect('/login');
    $this->post('/organizations', validOrganizationPayload())->assertRedirect('/login');
});
