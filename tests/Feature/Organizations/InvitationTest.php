<?php

declare(strict_types=1);

use App\Domain\Access\AccessControl;
use App\Domain\Access\Enums\Permission;
use App\Domain\Access\Enums\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Actions\InviteMember;
use App\Domain\Organizations\Enums\MembershipStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Notifications\MemberInvited;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Notification::fake();

    $this->organization = Organization::factory()->create(['name' => 'Alpha Traders']);
    $this->owner = actingAsMember($this->organization, Role::Owner->value);
});

/** Invite someone and return the plaintext token the email would carry. */
function inviteAndCaptureToken(
    Organization $organization,
    string $email,
    Role $role,
    User $invitedBy,
): string {
    return app(InviteMember::class)->handle($organization, $email, $role, $invitedBy)['token'];
}

// ---------------------------------------------------------------------------
// Sending
// ---------------------------------------------------------------------------

it('sends an invitation and stores only the hash of its token', function (): void {
    $this->post('/settings/members', [
        'email' => 'ayesha@example.com',
        'role' => Role::Accountant->value,
    ])->assertRedirect()->assertSessionHas('success');

    $membership = OrganizationMembership::query()
        ->where('invited_email', 'ayesha@example.com')
        ->firstOrFail();

    expect($membership->status)->toBe(MembershipStatus::Invited)
        ->and($membership->role)->toBe(Role::Accountant->value)
        ->and($membership->user_id)->toBeNull()
        ->and($membership->invitation_expires_at?->isFuture())->toBeTrue()
        // The token is a credential: a database backup must hand out none.
        ->and($membership->invitation_token_hash)->toHaveLength(64);

    Notification::assertSentOnDemand(MemberInvited::class);
});

it('lowercases the invited address', function (): void {
    $this->post('/settings/members', [
        'email' => 'MiXeD@Example.COM',
        'role' => Role::Viewer->value,
    ]);

    expect(OrganizationMembership::query()->where('invited_email', 'mixed@example.com')->exists())
        ->toBeTrue();
});

it('refuses to invite someone who is already a member', function (): void {
    $existing = User::factory()->create(['email' => 'already@example.com']);
    OrganizationMembership::query()->create([
        'organization_id' => $this->organization->getKey(),
        'user_id' => $existing->getKey(),
        'role' => Role::Viewer->value,
        'status' => MembershipStatus::Active,
        'joined_at' => now(),
    ]);

    $this->post('/settings/members', [
        'email' => 'already@example.com',
        'role' => Role::Viewer->value,
    ])->assertSessionHasErrors('email');
});

it('refuses to invite yourself', function (): void {
    $this->post('/settings/members', [
        'email' => $this->owner->email,
        'role' => Role::Admin->value,
    ])->assertSessionHasErrors('email');
});

it('stops anyone granting a role above their own', function (): void {
    $admin = Organization::factory()->create();
    actingAsMember($admin, Role::Admin->value);

    // An admin promoting an invitee to owner would be self-promotion by proxy.
    $this->post('/settings/members', [
        'email' => 'new-owner@example.com',
        'role' => Role::Owner->value,
    ])->assertSessionHasErrors('role');

    expect(OrganizationMembership::query()->where('invited_email', 'new-owner@example.com')->exists())
        ->toBeFalse();
});

it('does not let a bookkeeper invite at all', function (): void {
    $org = Organization::factory()->create();
    actingAsMember($org, Role::Bookkeeper->value);

    $this->post('/settings/members', [
        'email' => 'someone@example.com',
        'role' => Role::Viewer->value,
    ])->assertForbidden();
});

it('reissues a fresh token when an invitation is resent', function (): void {
    $first = inviteAndCaptureToken($this->organization, 'resend@example.com', Role::Viewer, $this->owner);
    $second = inviteAndCaptureToken($this->organization, 'resend@example.com', Role::Viewer, $this->owner);

    expect($second)->not->toBe($first);

    // Exactly one invitation, and the old link no longer works.
    expect(OrganizationMembership::query()->where('invited_email', 'resend@example.com')->count())
        ->toBe(1);

    $this->get("/invitations/{$first}")->assertRedirect('/login');
    $this->get("/invitations/{$second}")->assertOk();
});

// ---------------------------------------------------------------------------
// Accepting — someone with no account, which is the common case
// ---------------------------------------------------------------------------

it('offers account creation to an invitee who has none', function (): void {
    $token = inviteAndCaptureToken($this->organization, 'newcomer@example.com', Role::Accountant, $this->owner);

    $this->post('/logout');

    $this->get("/invitations/{$token}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invitations/Accept')
            ->where('mode', 'register')
            ->where('organization', 'Alpha Traders')
            ->where('invitedEmail', 'newcomer@example.com')
            ->where('role', 'Accountant'),
        );
});

it('creates the account and joins the organisation in one step', function (): void {
    $token = inviteAndCaptureToken($this->organization, 'newcomer@example.com', Role::Accountant, $this->owner);
    $this->post('/logout');

    $this->post("/invitations/{$token}/register", [
        'name' => 'Bilal Ahmed',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'newcomer@example.com')->firstOrFail();

    $this->assertAuthenticatedAs($user);

    expect($user->name)->toBe('Bilal Ahmed')
        // The invitation reached that address, which is the same proof a
        // verification email provides.
        ->and($user->hasVerifiedEmail())->toBeTrue();

    $membership = OrganizationMembership::query()
        ->where('user_id', $user->getKey())
        ->firstOrFail();

    expect($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->role)->toBe(Role::Accountant->value)
        ->and($membership->invitation_token_hash)->toBeNull();
});

it('gives the new member exactly the role they were invited as', function (): void {
    $token = inviteAndCaptureToken($this->organization, 'book@example.com', Role::Bookkeeper, $this->owner);
    $this->post('/logout');

    $this->post("/invitations/{$token}/register", [
        'name' => 'Sara Iqbal',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ]);

    $user = User::query()->where('email', 'book@example.com')->firstOrFail();
    $access = app(AccessControl::class);

    expect($access->allows($user, $this->organization, Permission::SalesCreate))->toBeTrue()
        // A bookkeeper prepares but does not post. That gap is the control.
        ->and($access->allows($user, $this->organization, Permission::AccountingPost))->toBeFalse();
});

it('lets an invitation link be used only once', function (): void {
    $token = inviteAndCaptureToken($this->organization, 'once@example.com', Role::Viewer, $this->owner);
    $this->post('/logout');

    $this->post("/invitations/{$token}/register", [
        'name' => 'First Use',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertRedirect('/dashboard');

    $this->post('/logout');

    $this->get("/invitations/{$token}")->assertRedirect('/login');
});

it('rejects a weak password', function (): void {
    $token = inviteAndCaptureToken($this->organization, 'weak@example.com', Role::Viewer, $this->owner);
    $this->post('/logout');

    $this->post("/invitations/{$token}/register", [
        'name' => 'Weak Password',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    expect(User::query()->where('email', 'weak@example.com')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Accepting — someone who already has an account
// ---------------------------------------------------------------------------

it('lets an existing user accept while signed in', function (): void {
    $existing = User::factory()->create(['email' => 'existing@example.com']);
    $token = inviteAndCaptureToken($this->organization, 'existing@example.com', Role::Viewer, $this->owner);

    $this->actingAs($existing);

    $this->get("/invitations/{$token}")
        ->assertInertia(fn (Assert $page) => $page->where('mode', 'accept'));

    $this->post("/invitations/{$token}/accept")->assertRedirect('/dashboard');

    $membership = app(TenantContext::class)->runUnscoped(
        fn () => OrganizationMembership::query()
            ->where('user_id', $existing->getKey())
            ->where('organization_id', $this->organization->getKey())
            ->firstOrFail(),
    );

    expect($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->joined_at)->not->toBeNull()
        // The link is spent.
        ->and($membership->invitation_token_hash)->toBeNull();
});

it('refuses acceptance by an account the invitation was not sent to', function (): void {
    User::factory()->create(['email' => 'intended@example.com']);
    $interloper = User::factory()->create(['email' => 'interloper@example.com']);

    $token = inviteAndCaptureToken($this->organization, 'intended@example.com', Role::Admin, $this->owner);

    $this->actingAs($interloper);

    $this->get("/invitations/{$token}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'wrong-account')
            ->where('signedInAs', 'interloper@example.com'),
        );

    $this->post("/invitations/{$token}/accept")->assertRedirect();

    expect(OrganizationMembership::query()
        ->where('organization_id', $this->organization->getKey())
        ->where('user_id', $interloper->getKey())
        ->exists())->toBeFalse();
});

it('tells an invitee with an account to sign in first', function (): void {
    User::factory()->create(['email' => 'has-account@example.com']);
    $token = inviteAndCaptureToken($this->organization, 'has-account@example.com', Role::Viewer, $this->owner);

    $this->post('/logout');

    $this->get("/invitations/{$token}")
        ->assertInertia(fn (Assert $page) => $page->where('mode', 'sign-in'));
});

it('turns away an expired invitation', function (): void {
    $token = inviteAndCaptureToken($this->organization, 'stale@example.com', Role::Viewer, $this->owner);

    OrganizationMembership::query()
        ->where('invited_email', 'stale@example.com')
        ->update(['invitation_expires_at' => now()->subDay()]);

    $this->post('/logout');

    $this->get("/invitations/{$token}")
        ->assertRedirect('/login')
        ->assertSessionHas('error');
});

it('turns away a token that was never issued', function (): void {
    $this->post('/logout');

    $this->get('/invitations/'.str_repeat('a', 48))->assertRedirect('/login');
});

// ---------------------------------------------------------------------------
// Managing people
// ---------------------------------------------------------------------------

it('lists members and pending invitations', function (): void {
    inviteAndCaptureToken($this->organization, 'pending@example.com', Role::Viewer, $this->owner);

    $this->get('/settings/members')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Members/Index')
            ->has('members', 2)
            ->where('can.invite', true)
            ->has('assignableRoles', 6),
        );
});

it('changes a role and audits it', function (): void {
    $member = User::factory()->create();
    $membership = OrganizationMembership::query()->create([
        'organization_id' => $this->organization->getKey(),
        'user_id' => $member->getKey(),
        'role' => Role::Viewer->value,
        'status' => MembershipStatus::Active,
        'joined_at' => now(),
    ]);

    $this->patch("/settings/members/{$membership->getKey()}", ['role' => Role::Accountant->value])
        ->assertRedirect()->assertSessionHas('success');

    expect($membership->fresh()?->role)->toBe(Role::Accountant->value)
        ->and(AuditLog::query()->where('action', 'member.role_changed')->count())->toBe(1);
});

it('will not let the last owner be demoted or removed', function (): void {
    $ownMembership = OrganizationMembership::query()
        ->where('user_id', $this->owner->getKey())
        ->firstOrFail();

    $this->patch("/settings/members/{$ownMembership->getKey()}", ['role' => Role::Viewer->value])
        ->assertSessionHasErrors('role');

    expect($ownMembership->fresh()?->role)->toBe(Role::Owner->value);
});

it('will not let someone remove their own access', function (): void {
    $ownMembership = OrganizationMembership::query()
        ->where('user_id', $this->owner->getKey())
        ->firstOrFail();

    $this->delete("/settings/members/{$ownMembership->getKey()}")
        ->assertSessionHasErrors('member');
});

it('revokes a pending invitation', function (): void {
    inviteAndCaptureToken($this->organization, 'revoke-me@example.com', Role::Viewer, $this->owner);

    $membership = OrganizationMembership::query()
        ->where('invited_email', 'revoke-me@example.com')
        ->firstOrFail();

    $this->delete("/settings/members/{$membership->getKey()}")
        ->assertRedirect()->assertSessionHas('success');

    expect(OrganizationMembership::query()->whereKey($membership->getKey())->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'member.invitation_revoked')->count())->toBe(1);
});

/*
 * OrganizationMembership deliberately carries no global scope — the switcher
 * must read memberships across organisations before one is active — and RLS
 * permits a user to see their OWN membership everywhere for the same reason.
 * Every query and every route binding therefore has to scope itself, and
 * these are the tests that prove it does.
 */
it('lists only this organisation\'s members, not the viewer\'s other memberships', function (): void {
    // The same owner also belongs to two other organisations.
    $second = Organization::factory()->create(['name' => 'Beta Foods']);
    $third = Organization::factory()->create(['name' => 'Gamma Textiles']);

    foreach ([$second, $third] as $other) {
        OrganizationMembership::query()->create([
            'organization_id' => $other->getKey(),
            'user_id' => $this->owner->getKey(),
            'role' => Role::Owner->value,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
    }

    // Alpha Traders has exactly one member: the owner.
    $this->get('/settings/members')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('members', 1));
});

it('refuses to change a role on a membership from another organisation', function (): void {
    $other = Organization::factory()->create();

    // The owner is a mere viewer over in the other organisation...
    $foreign = OrganizationMembership::query()->create([
        'organization_id' => $other->getKey(),
        'user_id' => $this->owner->getKey(),
        'role' => Role::Viewer->value,
        'status' => MembershipStatus::Active,
        'joined_at' => now(),
    ]);

    /*
     * ...and holds users.update_role in THIS one. Without an explicit scope
     * check the binding would resolve that foreign row — RLS shows it to them
     * because it is their own — and they could promote themselves to owner of
     * an organisation they only read.
     */
    $this->patch("/settings/members/{$foreign->getKey()}", ['role' => Role::Owner->value])
        ->assertNotFound();

    expect($foreign->fresh()?->role)->toBe(Role::Viewer->value);
});

it('refuses to remove a membership from another organisation', function (): void {
    $other = Organization::factory()->create();

    $foreign = OrganizationMembership::query()->create([
        'organization_id' => $other->getKey(),
        'user_id' => $this->owner->getKey(),
        'role' => Role::Viewer->value,
        'status' => MembershipStatus::Active,
        'joined_at' => now(),
    ]);

    $this->delete("/settings/members/{$foreign->getKey()}")->assertNotFound();

    expect(OrganizationMembership::query()->whereKey($foreign->getKey())->exists())->toBeTrue();
});

it('counts owners within this organisation only when protecting the last one', function (): void {
    // An owner elsewhere must not count towards this organisation's owners.
    $other = Organization::factory()->create();
    OrganizationMembership::query()->create([
        'organization_id' => $other->getKey(),
        'user_id' => User::factory()->create()->getKey(),
        'role' => Role::Owner->value,
        'status' => MembershipStatus::Active,
        'joined_at' => now(),
    ]);

    $ownMembership = OrganizationMembership::query()
        ->where('organization_id', $this->organization->getKey())
        ->where('user_id', $this->owner->getKey())
        ->firstOrFail();

    $this->patch("/settings/members/{$ownMembership->getKey()}", ['role' => Role::Viewer->value])
        ->assertSessionHasErrors('role');

    expect($ownMembership->fresh()?->role)->toBe(Role::Owner->value);
});

it('lets a viewer see who has access but gives them no controls', function (): void {
    $org = Organization::factory()->create();
    actingAsMember($org, Role::Viewer->value);

    // A viewer reads everything and changes nothing — including this page.
    $this->get('/settings/members')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.invite', false)
            ->where('can.update_role', false)
            ->where('can.remove', false)
            // Offering a role they cannot grant would be a lie in the UI.
            ->has('assignableRoles', 0),
        );
});

it('refuses a viewer every mutating action on people', function (): void {
    $org = Organization::factory()->create();
    $viewer = actingAsMember($org, Role::Viewer->value);

    $membership = OrganizationMembership::query()
        ->where('user_id', $viewer->getKey())
        ->firstOrFail();

    $this->post('/settings/members', [
        'email' => 'nope@example.com',
        'role' => Role::Viewer->value,
    ])->assertForbidden();

    $this->patch("/settings/members/{$membership->getKey()}", ['role' => Role::Owner->value])
        ->assertForbidden();

    $this->delete("/settings/members/{$membership->getKey()}")->assertForbidden();
});
