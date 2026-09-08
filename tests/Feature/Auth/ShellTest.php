<?php

declare(strict_types=1);

use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The application shell: dashboard, shared props, organisation context, and
 * the placeholder pages for modules that are not built yet.
 */
it('shows the dashboard with the active organisation in shared props', function (): void {
    $organization = Organization::factory()->create(['name' => 'Alpha Traders', 'base_currency' => 'PKR']);
    $user = actingAsMember($organization);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('auth.user.id', $user->getKey())
            ->where('auth.user.initials', fn (string $initials) => mb_strlen($initials) === 2)
            ->where('organization.name', 'Alpha Traders')
            ->where('organization.base_currency', 'PKR')
            ->where('hasLedgerData', false)
            ->has('metrics', 4)
            // No fabricated zeros: every figure is honestly unknown until the
            // ledger exists.
            ->where('metrics.0.value', null),
        );
});

it('lists only the organisations the user belongs to in the switcher', function (): void {
    $mine = Organization::factory()->create(['name' => 'Mine']);
    Organization::factory()->create(['name' => 'Someone Else\'s']);

    actingAsMember($mine);

    $this->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->has('organizations', 1)
            ->where('organizations.0.name', 'Mine')
            ->where('organizations.0.role', 'owner'),
        );
});

it('signs in with no organisation and gets a null organisation rather than an error', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('organization', null)
            ->has('organizations', 0),
        );
});

it('drops a stale session organisation the user no longer belongs to', function (): void {
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();

    $user = actingAsMember($mine);

    // Session points at an organisation this user was never a member of.
    $this->actingAs($user)
        ->withSession(['active_organization_id' => $theirs->getKey()])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('organization', null))
        ->assertSessionMissing('active_organization_id');
});

it('switches organisation and records the choice', function (): void {
    $alpha = Organization::factory()->create(['slug' => 'alpha']);
    $beta = Organization::factory()->create(['slug' => 'beta']);

    $user = actingAsMember($alpha);
    $user->memberships()->create([
        'organization_id' => $beta->getKey(),
        'role' => 'accountant',
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $this->post('/organizations/beta/switch')
        ->assertRedirect('/dashboard')
        ->assertSessionHas('active_organization_id', $beta->getKey())
        ->assertSessionHas('success');

    expect($user->fresh()?->last_organization_id)->toBe($beta->getKey());
});

it('returns 404, not 403, when switching to an organisation the user is not in', function (): void {
    $alpha = Organization::factory()->create(['slug' => 'alpha']);
    Organization::factory()->create(['slug' => 'secret']);

    actingAsMember($alpha);

    // Existence is itself information; a stranger is told nothing.
    $this->post('/organizations/secret/switch')->assertNotFound();
});

it('renders a designed placeholder for modules that are not built yet', function (): void {
    actingAsMember(Organization::factory()->create());

    // Purchases has not landed, so it still explains itself rather than
    // 404ing — the information architecture is real from day one.
    $this->get('/purchases/bills')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ModulePlaceholder')
            ->where('module', 'Purchases')
            ->where('section', 'Bills')
            ->where('phase', 4),
        );

    // Sales has landed, so its screens are real.
    $this->get('/sales/invoices')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Sales/Documents/Index'));

    $this->get('/sales')->assertRedirect('/sales/invoices');

    /*
     * Accounting has landed, so its section header is a real destination and
     * the placeholder now covers only the part still to come. This asserts
     * both halves of that: the section redirects, and the unbuilt submodule
     * still explains itself.
     */
    $this->get('/accounting')->assertRedirect('/accounting/accounts');

    $this->get('/accounting/opening-balances')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ModulePlaceholder')
            ->where('module', 'Opening Balances')
            ->where('phase', 3),
        );
});

it('does not treat arbitrary paths as modules', function (): void {
    actingAsMember(Organization::factory()->create());

    $this->get('/definitely-not-a-module')->assertNotFound();
});

it('persists theme and density without a page reload', function (): void {
    $user = actingAsMember(Organization::factory()->create());

    $this->from('/dashboard')
        ->patch('/settings/appearance', ['theme' => 'dark', 'density' => 'comfortable'])
        ->assertRedirect('/dashboard');

    expect($user->fresh())
        ->theme->toBe('dark')
        ->density->toBe('comfortable');
});

it('rejects an unknown theme', function (): void {
    actingAsMember(Organization::factory()->create());

    $this->patch('/settings/appearance', ['theme' => 'sepia'])
        ->assertSessionHasErrors('theme');
});

it('reports health without naming its dependencies', function (): void {
    $this->get('/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});
