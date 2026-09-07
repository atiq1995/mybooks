<?php

declare(strict_types=1);

use App\Domain\Access\Enums\Role;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->organization = Organization::factory()->create(['name' => 'Alpha Traders']);
});

// ---------------------------------------------------------------------------
// Profile
// ---------------------------------------------------------------------------

it('shows the profile screen with the signed-in person\'s details', function (): void {
    $user = actingAsMember($this->organization, Role::Accountant->value);

    $this->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Profile')
            ->where('profile.name', $user->name)
            ->where('profile.email', $user->email)
            ->where('profile.email_verified', true)
            ->has('options.timezones'),
        );
});

it('updates the profile through Fortify', function (): void {
    $user = actingAsMember($this->organization);

    $this->put('/user/profile-information', [
        'name' => 'Ayesha Khan',
        'email' => $user->email,
    ])->assertRedirect();

    expect($user->fresh()?->name)->toBe('Ayesha Khan');
});

// ---------------------------------------------------------------------------
// Security
// ---------------------------------------------------------------------------

it('tells a role that can post that two-factor matters to them', function (): void {
    actingAsMember($this->organization, Role::Accountant->value);

    $this->get('/settings/security')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Security')
            ->where('twoFactor.enabled', false)
            // An accountant can post to the ledger, so it does.
            ->where('twoFactor.required_for_your_role', true)
            ->has('passkeys', 0),
        );
});

it('does not claim two-factor is required for a role that cannot post', function (): void {
    actingAsMember($this->organization, Role::Viewer->value);

    $this->get('/settings/security')
        ->assertInertia(fn (Assert $page) => $page
            // A viewer changes nothing, so nagging them would be noise.
            ->where('twoFactor.required_for_your_role', false),
        );
});

it('reports two-factor as on once it is confirmed', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['active_organization_id' => $this->organization->getKey()]);

    $user->memberships()->create([
        'organization_id' => $this->organization->getKey(),
        'role' => Role::Owner->value,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $this->get('/settings/security')
        ->assertInertia(fn (Assert $page) => $page->where('twoFactor.enabled', true));
});

it('will not enable two-factor without a recent password confirmation', function (): void {
    $user = actingAsMember($this->organization);

    /*
     * Enabling two-factor is a sensitive action, so Fortify's RequirePassword
     * middleware turns this away until the password has been confirmed. This
     * is exactly why the settings screen collects the password inline and
     * talks to these endpoints as JSON — as an Inertia visit the redirect
     * would abandon a half-finished setup.
     */
    $this->post('/user/two-factor-authentication')->assertRedirect();

    expect($user->fresh()?->two_factor_secret)->toBeNull();
});

it('enables, shows a QR, confirms and disables two-factor', function (): void {
    $user = actingAsMember($this->organization);

    // What the screen does first.
    $this->getJson('/user/confirmed-password-status')
        ->assertOk()
        ->assertJson(['confirmed' => false]);

    $this->postJson('/user/confirm-password', ['password' => 'password'])->assertCreated();

    $this->getJson('/user/confirmed-password-status')->assertJson(['confirmed' => true]);

    $this->postJson('/user/two-factor-authentication')->assertOk();

    /*
     * Enabled but NOT yet in force: the secret exists, and the factor does not
     * count until a real code proves the authenticator was set up correctly.
     * Confirming last is what stops someone locking themselves out with a
     * mis-scanned QR.
     */
    expect($user->fresh()?->two_factor_secret)->not->toBeNull()
        ->and($user->fresh()?->hasTwoFactorEnabled())->toBeFalse();

    $this->getJson('/user/two-factor-qr-code')->assertOk()->assertJsonStructure(['svg']);
    $this->getJson('/user/two-factor-secret-key')->assertOk()->assertJsonStructure(['secretKey']);

    // A wrong code must not turn it on.
    $this->postJson('/user/confirmed-two-factor-authentication', ['code' => '000000'])
        ->assertStatus(422);

    expect($user->fresh()?->hasTwoFactorEnabled())->toBeFalse();

    $this->deleteJson('/user/two-factor-authentication')->assertOk();

    expect($user->fresh()?->two_factor_secret)->toBeNull();
});

it('changes a password and rejects a wrong current one', function (): void {
    $user = actingAsMember($this->organization);

    // Fortify validates password updates in their own error bag.
    $this->from('/settings/security')->put('/user/password', [
        'current_password' => 'not-the-password',
        'password' => 'a-brand-new-long-password',
        'password_confirmation' => 'a-brand-new-long-password',
    ])->assertSessionHasErrors('current_password', null, 'updatePassword');

    expect(Hash::check('password', (string) $user->fresh()?->password))->toBeTrue();

    $this->put('/user/password', [
        'current_password' => 'password',
        'password' => 'a-brand-new-long-password',
        'password_confirmation' => 'a-brand-new-long-password',
    ])->assertRedirect();

    expect(Hash::check('a-brand-new-long-password', (string) $user->fresh()?->password))
        ->toBeTrue();
});

// ---------------------------------------------------------------------------
// Appearance
// ---------------------------------------------------------------------------

it('shows the appearance screen with the current preferences', function (): void {
    actingAsMember($this->organization);

    $this->get('/settings/appearance-preferences')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Appearance')
            ->where('appearance.theme', 'system')
            ->where('appearance.density', 'compact'),
        );
});

// ---------------------------------------------------------------------------
// Routing — these must not be swallowed by the module placeholder catch-all
// ---------------------------------------------------------------------------

it('routes every settings screen to its own page, not the placeholder', function (): void {
    actingAsMember($this->organization);

    $expected = [
        '/settings/profile' => 'Settings/Profile',
        '/settings/security' => 'Settings/Security',
        '/settings/appearance-preferences' => 'Settings/Appearance',
        '/settings/members' => 'Settings/Members/Index',
    ];

    foreach ($expected as $path => $component) {
        $this->get($path)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }

    // ...while a settings area that genuinely is not built still lands on the
    // placeholder rather than 404ing.
    $this->get('/settings/taxes')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ModulePlaceholder'));
});

it('turns guests away from every settings screen', function (): void {
    foreach (['/settings/profile', '/settings/security', '/settings/appearance-preferences'] as $path) {
        $this->get($path)->assertRedirect('/login');
    }
});
