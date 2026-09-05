<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Access\Enums\Permission;
use App\Domain\Access\Enums\Role;
use App\Domain\Organizations\Enums\MembershipStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Models\User;

/**
 * Answers one question: may this user do this thing in this organisation?
 *
 * Effective permissions are the role's set, plus per-membership grants, minus
 * per-membership revocations — in that order, so a revocation always wins.
 *
 * Results are memoised per request. They are deliberately NOT cached in Redis:
 * a permission cache that outlives the request is a permission cache that can
 * serve a revoked capability, and "we removed their access an hour ago" is not
 * an acceptable answer in an accounting system.
 *
 * @see Role
 * @see SECURITY.md section 4
 */
final class AccessControl
{
    /**
     * Memo keyed by "userId:organizationId".
     *
     * @var array<string, array<string, true>>
     */
    private array $memo = [];

    /**
     * @var array<string, OrganizationMembership|null>
     */
    private array $membershipMemo = [];

    public function allows(User $user, Organization $organization, Permission $permission): bool
    {
        return isset($this->effective($user, $organization)[$permission->value]);
    }

    public function denies(User $user, Organization $organization, Permission $permission): bool
    {
        return ! $this->allows($user, $organization, $permission);
    }

    /**
     * The user's role in this organisation, or null if they are not an active
     * member of it.
     */
    public function roleFor(User $user, Organization $organization): ?Role
    {
        $membership = $this->membership($user, $organization);

        if ($membership === null) {
            return null;
        }

        return Role::tryFrom($membership->role);
    }

    /**
     * Every permission this user effectively holds here.
     *
     * @return list<string>
     */
    public function permissionsFor(User $user, Organization $organization): array
    {
        return array_keys($this->effective($user, $organization));
    }

    /**
     * Clear the memo — call after changing a role or a membership so the rest
     * of the request sees the new answer rather than the old one.
     */
    public function forget(?User $user = null, ?Organization $organization = null): void
    {
        if ($user === null || $organization === null) {
            $this->memo = [];
            $this->membershipMemo = [];

            return;
        }

        unset(
            $this->memo[$this->key($user, $organization)],
            $this->membershipMemo[$this->key($user, $organization)],
        );
    }

    /**
     * @return array<string, true> permission value => true, for O(1) lookup
     */
    private function effective(User $user, Organization $organization): array
    {
        $key = $this->key($user, $organization);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $membership = $this->membership($user, $organization);

        // Not an active member: no permissions at all. Not "some" — none.
        if ($membership === null) {
            return $this->memo[$key] = [];
        }

        $role = Role::tryFrom($membership->role);

        // An unrecognised role string means the catalogue changed under a
        // stored value. Fail CLOSED: grant nothing rather than guess.
        if ($role === null) {
            return $this->memo[$key] = [];
        }

        $effective = [];

        foreach ($role->permissions() as $permission) {
            $effective[$permission->value] = true;
        }

        foreach ($membership->granted_permissions ?? [] as $granted) {
            // Only real permissions can be granted; an unknown string is
            // ignored rather than silently becoming a wildcard.
            if (Permission::tryFrom((string) $granted) !== null) {
                $effective[(string) $granted] = true;
            }
        }

        // Revocations are applied last and unconditionally: they win over both
        // the role and any explicit grant.
        foreach ($membership->revoked_permissions ?? [] as $revoked) {
            unset($effective[(string) $revoked]);
        }

        return $this->memo[$key] = $effective;
    }

    private function membership(User $user, Organization $organization): ?OrganizationMembership
    {
        $key = $this->key($user, $organization);

        if (array_key_exists($key, $this->membershipMemo)) {
            return $this->membershipMemo[$key];
        }

        return $this->membershipMemo[$key] = OrganizationMembership::query()
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organization->getKey())
            ->where('status', MembershipStatus::Active)
            ->first();
    }

    private function key(User $user, Organization $organization): string
    {
        // Both models use UUID keys, so these are strings — cast explicitly
        // rather than relying on Eloquent's mixed return type.
        return $user->id.':'.$organization->id;
    }
}
