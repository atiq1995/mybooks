<?php

declare(strict_types=1);

use App\Domain\Access\Enums\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->organization = Organization::factory()->onboarding()->create([
        'name' => 'Alpha Traders',
        'country_code' => 'PK',
        'base_currency' => 'PKR',
        'fiscal_year_start_month' => 7,
    ]);

    $this->owner = actingAsMember($this->organization, Role::Owner->value);
});

it('shows the wizard with the organisation it is setting up', function (): void {
    $this->get('/onboarding')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/Index')
            ->where('organization.name', 'Alpha Traders')
            ->where('organization.base_currency', 'PKR')
            ->where('organization.fiscal_year_start_month', 7)
            ->where('organization.onboarding_complete', false)
            ->where('canInvite', true)
            ->has('options.months', 12),
        );
});

it('saves business details', function (): void {
    $this->from('/onboarding')->patch('/onboarding/details', [
        'legal_name' => 'Alpha Traders (Private) Limited',
        'tax_registration_number' => '1234567-8',
        'sales_tax_registration_number' => '12-34-5678-901-23',
        'phone' => '+92 21 1234567',
        'email' => 'accounts@alpha.example',
        'website' => 'https://alpha.example',
        'address' => [
            'line1' => '12 Shahrah-e-Faisal',
            'city' => 'Karachi',
            'state' => 'Sindh',
            'postal_code' => '75350',
        ],
    ])->assertRedirect('/onboarding')->assertSessionHas('success');

    $organization = $this->organization->fresh();

    expect($organization?->legal_name)->toBe('Alpha Traders (Private) Limited')
        ->and($organization?->tax_registration_number)->toBe('1234567-8')
        ->and($organization?->address)->toHaveKey('city', 'Karachi');
});

it('rejects a website that is not a full address', function (): void {
    $this->patch('/onboarding/details', ['website' => 'alpha.example'])
        ->assertSessionHasErrors('website');
});

it('rejects an invalid business email', function (): void {
    $this->patch('/onboarding/details', ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');
});

it('audits a details change but not a no-op save', function (): void {
    $this->patch('/onboarding/details', ['legal_name' => 'Alpha Traders (Private) Limited']);

    expect(AuditLog::query()->where('action', 'organization.updated')->count())->toBe(1);

    // Saving the identical value again changes nothing, so it records nothing.
    $this->patch('/onboarding/details', ['legal_name' => 'Alpha Traders (Private) Limited']);

    expect(AuditLog::query()->where('action', 'organization.updated')->count())->toBe(1);
});

it('completes setup and sends the user to the dashboard', function (): void {
    $this->post('/onboarding/complete')
        ->assertRedirect('/dashboard')
        ->assertSessionHas('success');

    expect($this->organization->fresh()?->hasCompletedOnboarding())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'organization.onboarding_completed')->count())
        ->toBe(1);
});

it('treats completing an already-complete setup as a no-op', function (): void {
    $this->post('/onboarding/complete');
    $completedAt = $this->organization->fresh()?->onboarding_completed_at;

    // A refresh of the finish step must not rewrite history.
    $this->post('/onboarding/complete')->assertRedirect('/dashboard');

    expect($this->organization->fresh()?->onboarding_completed_at?->toIso8601String())
        ->toBe($completedAt?->toIso8601String())
        ->and(AuditLog::query()->where('action', 'organization.onboarding_completed')->count())
        ->toBe(1);
});

it('does not let a viewer change the organisation during setup', function (): void {
    $viewer = Organization::factory()->onboarding()->create();
    actingAsMember($viewer, Role::Viewer->value);

    $this->patch('/onboarding/details', ['legal_name' => 'Hijacked Ltd'])
        ->assertForbidden();

    expect($viewer->fresh()?->legal_name)->not->toBe('Hijacked Ltd');
});

it('tells a member without invite rights that they cannot invite', function (): void {
    $organization = Organization::factory()->onboarding()->create();
    actingAsMember($organization, Role::Bookkeeper->value);

    $this->get('/onboarding')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canInvite', false));
});
