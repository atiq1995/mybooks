<?php

declare(strict_types=1);

use App\Domain\Access\AccessControl;
use App\Domain\Access\Enums\Permission;
use App\Domain\Access\Enums\Role;
use App\Domain\Organizations\Enums\MembershipStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * The RBAC matrix.
 *
 * Two things are being protected here: that each role carries what it should,
 * and — more importantly — that the roles designed for separation of duties
 * genuinely cannot do the thing they were separated from.
 */
beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->access = app(AccessControl::class);
});

function memberWithRole(Organization $organization, Role $role): User
{
    $user = User::factory()->create();

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $user->getKey(),
        'role' => $role->value,
        'status' => MembershipStatus::Active,
        'joined_at' => now(),
    ]);

    return $user;
}

// ---------------------------------------------------------------------------
// Role bundles
// ---------------------------------------------------------------------------

it('gives the owner every permission without exception', function (): void {
    $owner = memberWithRole($this->organization, Role::Owner);

    foreach (Permission::cases() as $permission) {
        expect($this->access->allows($owner, $this->organization, $permission))
            ->toBeTrue("owner should hold {$permission->value}");
    }
});

it('gives the admin everything except archiving the organisation', function (): void {
    $admin = memberWithRole($this->organization, Role::Admin);

    expect($this->access->allows($admin, $this->organization, Permission::OrganizationArchive))
        ->toBeFalse();

    foreach (Permission::cases() as $permission) {
        if ($permission === Permission::OrganizationArchive) {
            continue;
        }

        expect($this->access->allows($admin, $this->organization, $permission))
            ->toBeTrue("admin should hold {$permission->value}");
    }
});

it('gives the viewer read access and nothing else', function (): void {
    $viewer = memberWithRole($this->organization, Role::Viewer);

    foreach (Permission::cases() as $permission) {
        $expected = str_ends_with($permission->value, '.view');

        expect($this->access->allows($viewer, $this->organization, $permission))
            ->toBe($expected, "viewer / {$permission->value}");
    }
});

// ---------------------------------------------------------------------------
// Separation of duties — the controls that actually matter
// ---------------------------------------------------------------------------

it('does not let a bookkeeper post, approve or reconcile', function (): void {
    $bookkeeper = memberWithRole($this->organization, Role::Bookkeeper);

    // They can prepare the work...
    expect($this->access->allows($bookkeeper, $this->organization, Permission::SalesCreate))->toBeTrue()
        ->and($this->access->allows($bookkeeper, $this->organization, Permission::PurchasesCreate))->toBeTrue()
        ->and($this->access->allows($bookkeeper, $this->organization, Permission::ExpensesCreate))->toBeTrue();

    // ...and commit none of it. This gap is the control.
    expect($this->access->allows($bookkeeper, $this->organization, Permission::AccountingPost))->toBeFalse()
        ->and($this->access->allows($bookkeeper, $this->organization, Permission::AccountingReverse))->toBeFalse()
        ->and($this->access->allows($bookkeeper, $this->organization, Permission::PurchasesApprove))->toBeFalse()
        ->and($this->access->allows($bookkeeper, $this->organization, Permission::ExpensesApprove))->toBeFalse()
        ->and($this->access->allows($bookkeeper, $this->organization, Permission::BankingReconcile))->toBeFalse()
        ->and($this->access->allows($bookkeeper, $this->organization, Permission::SalesRecordPayment))->toBeFalse();
});

it('does not let an approver create the documents they approve', function (): void {
    $approver = memberWithRole($this->organization, Role::Approver);

    expect($this->access->allows($approver, $this->organization, Permission::ExpensesApprove))->toBeTrue()
        ->and($this->access->allows($approver, $this->organization, Permission::PurchasesApprove))->toBeTrue();

    // Approving one's own work is impossible by construction, not by policy.
    expect($this->access->allows($approver, $this->organization, Permission::ExpensesCreate))->toBeFalse()
        ->and($this->access->allows($approver, $this->organization, Permission::PurchasesCreate))->toBeFalse()
        ->and($this->access->allows($approver, $this->organization, Permission::SalesCreate))->toBeFalse();
});

it('withholds posting into a closed period from everyone but owner and admin', function (): void {
    foreach ([Role::Accountant, Role::Bookkeeper, Role::Approver, Role::Viewer] as $role) {
        $user = memberWithRole($this->organization, $role);

        expect($this->access->allows($user, $this->organization, Permission::AccountingPostToClosedPeriod))
            ->toBeFalse("{$role->value} must not post into a closed period");
    }

    // The accountant still owns the books in every other respect.
    $accountant = memberWithRole($this->organization, Role::Accountant);
    expect($this->access->allows($accountant, $this->organization, Permission::AccountingPost))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Membership, grants and revocations
// ---------------------------------------------------------------------------

it('grants nothing at all to someone who is not a member', function (): void {
    $stranger = User::factory()->create();

    foreach (Permission::cases() as $permission) {
        expect($this->access->allows($stranger, $this->organization, $permission))->toBeFalse();
    }

    expect($this->access->roleFor($stranger, $this->organization))->toBeNull();
});

it('grants nothing to a suspended membership', function (): void {
    $user = memberWithRole($this->organization, Role::Owner);

    OrganizationMembership::query()
        ->where('user_id', $user->getKey())
        ->update(['status' => MembershipStatus::Suspended]);

    $this->access->forget();

    expect($this->access->allows($user, $this->organization, Permission::SalesView))->toBeFalse();
});

it('adds an individually granted permission on top of the role', function (): void {
    $viewer = memberWithRole($this->organization, Role::Viewer);

    expect($this->access->allows($viewer, $this->organization, Permission::SalesCreate))->toBeFalse();

    OrganizationMembership::query()
        ->where('user_id', $viewer->getKey())
        ->update(['granted_permissions' => [Permission::SalesCreate->value]]);

    $this->access->forget();

    expect($this->access->allows($viewer, $this->organization, Permission::SalesCreate))->toBeTrue();
});

it('lets a revocation beat both the role and an explicit grant', function (): void {
    $owner = memberWithRole($this->organization, Role::Owner);

    OrganizationMembership::query()
        ->where('user_id', $owner->getKey())
        ->update([
            'granted_permissions' => [Permission::AccountingPost->value],
            'revoked_permissions' => [Permission::AccountingPost->value],
        ]);

    $this->access->forget();

    // Revocation is applied last and unconditionally.
    expect($this->access->allows($owner, $this->organization, Permission::AccountingPost))->toBeFalse()
        ->and($this->access->allows($owner, $this->organization, Permission::SalesView))->toBeTrue();
});

it('ignores an unknown string in the granted list rather than treating it as a wildcard', function (): void {
    $viewer = memberWithRole($this->organization, Role::Viewer);

    OrganizationMembership::query()
        ->where('user_id', $viewer->getKey())
        ->update(['granted_permissions' => ['accounting.everything', '*']]);

    $this->access->forget();

    expect($this->access->allows($viewer, $this->organization, Permission::AccountingPost))->toBeFalse();
});

it('fails closed when the stored role is not in the catalogue', function (): void {
    $user = memberWithRole($this->organization, Role::Owner);

    OrganizationMembership::query()
        ->where('user_id', $user->getKey())
        ->update(['role' => 'wizard']);

    $this->access->forget();

    foreach (Permission::cases() as $permission) {
        expect($this->access->allows($user, $this->organization, $permission))->toBeFalse();
    }
});

// ---------------------------------------------------------------------------
// Cross-tenant
// ---------------------------------------------------------------------------

it('does not carry a role from one organisation into another', function (): void {
    $other = Organization::factory()->create();
    $owner = memberWithRole($this->organization, Role::Owner);

    expect($this->access->allows($owner, $this->organization, Permission::AccountingPost))->toBeTrue()
        ->and($this->access->allows($owner, $other, Permission::AccountingPost))->toBeFalse()
        ->and($this->access->allows($owner, $other, Permission::SalesView))->toBeFalse();
});

it('lets the same person hold different roles in different organisations', function (): void {
    $second = Organization::factory()->create();
    $user = memberWithRole($this->organization, Role::Accountant);

    OrganizationMembership::query()->create([
        'organization_id' => $second->getKey(),
        'user_id' => $user->getKey(),
        'role' => Role::Viewer->value,
        'status' => MembershipStatus::Active,
        'joined_at' => now(),
    ]);

    expect($this->access->allows($user, $this->organization, Permission::AccountingPost))->toBeTrue()
        ->and($this->access->allows($user, $second, Permission::AccountingPost))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Gate integration — what the rest of the application actually calls
// ---------------------------------------------------------------------------

it('answers through the Gate for the active organisation', function (): void {
    $user = actingAsMember($this->organization, Role::Accountant->value);

    expect($user->can(Permission::AccountingPost->value))->toBeTrue()
        ->and($user->can(Permission::OrganizationArchive->value))->toBeFalse();
});

it('denies every permission through the Gate when no organisation is active', function (): void {
    $user = actingAsMember($this->organization, Role::Owner->value);

    app(TenantContext::class)->clear();

    expect($user->can(Permission::SalesView->value))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Role assignment hierarchy
// ---------------------------------------------------------------------------

it('stops anyone from assigning a role above their own', function (): void {
    expect(Role::Owner->canAssign(Role::Owner))->toBeTrue()
        ->and(Role::Admin->canAssign(Role::Owner))->toBeFalse()
        ->and(Role::Admin->canAssign(Role::Admin))->toBeTrue()
        ->and(Role::Accountant->assignableRoles())->toBe([])
        ->and(Role::Bookkeeper->assignableRoles())->toBe([])
        ->and(Role::Viewer->assignableRoles())->toBe([]);
});

// ---------------------------------------------------------------------------
// Catalogue integrity
// ---------------------------------------------------------------------------

it('keeps every permission reachable from at least one role', function (): void {
    $covered = [];

    foreach (Role::cases() as $role) {
        foreach ($role->permissions() as $permission) {
            $covered[$permission->value] = true;
        }
    }

    $orphans = array_diff(Permission::values(), array_keys($covered));

    expect($orphans)->toBe([], 'permissions no role can hold: '.implode(', ', $orphans));
});

it('gives every permission a label and a two-factor decision', function (): void {
    foreach (Permission::cases() as $permission) {
        expect($permission->label())->not->toBe('')
            ->and($permission->area())->not->toBe('');
    }

    // The consequential ones must be covered.
    expect(Permission::AccountingPost->requiresTwoFactor())->toBeTrue()
        ->and(Permission::BankingReconcile->requiresTwoFactor())->toBeTrue()
        ->and(Permission::SalesView->requiresTwoFactor())->toBeFalse();
});
