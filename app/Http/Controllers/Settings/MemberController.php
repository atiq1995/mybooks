<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Access\AccessControl;
use App\Domain\Access\Enums\Permission;
use App\Domain\Access\Enums\Role;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organizations\Actions\InviteMember;
use App\Domain\Organizations\Enums\MembershipStatus;
use App\Domain\Organizations\Exceptions\InvitationException;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\InviteMemberRequest;
use App\Http\Requests\Settings\UpdateMemberRoleRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who has access to this organisation, and what they may do.
 */
final class MemberController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AccessControl $access,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::UsersView->value);

        $user = $request->user();
        $organization = $this->tenant->organization();
        $actingRole = $user === null ? null : $this->access->roleFor($user, $organization);

        /*
         * Scoped EXPLICITLY to the active organisation.
         *
         * OrganizationMembership deliberately carries no global scope — the
         * organisation switcher has to read a user's memberships ACROSS
         * organisations before any one of them is active. Row-level security
         * permits that too (`user_id = app_current_user_id()`), so without
         * this filter the page would list the signed-in user's membership of
         * every other organisation alongside this one's members.
         */
        $members = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->with('user')
            ->orderByRaw("CASE status WHEN 'invited' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->get()
            ->map(fn (OrganizationMembership $m): array => [
                'id' => $m->id,
                'name' => $m->user?->name,
                'email' => $m->emailAddress(),
                'role' => $m->role,
                'role_label' => Role::tryFrom($m->role)?->label() ?? $m->role,
                'status' => $m->status->value,
                'status_label' => $m->status->label(),
                'joined_at' => $m->joined_at?->toDateString(),
                'invited_at' => $m->invited_at?->toDateString(),
                'invitation_expired' => $m->invitationHasExpired(),
                'is_you' => $m->user_id !== null && $m->user_id === $user?->getKey(),
                'two_factor_enabled' => $m->user?->hasTwoFactorEnabled() ?? false,
            ])
            ->all();

        return Inertia::render('Settings/Members/Index', [
            'members' => $members,
            // Only roles the acting user may actually grant — the UI should
            // not offer a choice the server will refuse.
            'assignableRoles' => array_map(
                fn (Role $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                    'description' => $role->description(),
                ],
                $actingRole?->assignableRoles() ?? [],
            ),
            'can' => [
                'invite' => $user?->can(Permission::UsersInvite->value) ?? false,
                'update_role' => $user?->can(Permission::UsersUpdateRole->value) ?? false,
                'remove' => $user?->can(Permission::UsersRemove->value) ?? false,
            ],
        ]);
    }

    public function invite(InviteMemberRequest $request, InviteMember $inviteMember): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $role = Role::from($request->string('role')->toString());

        try {
            $inviteMember->handle(
                $this->tenant->organization(),
                $request->string('email')->toString(),
                $role,
                $user,
            );
        } catch (InvitationException $e) {
            // The exception knows which input it belongs against.
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        return back()->with(
            'success',
            "Invitation sent to {$request->string('email')->toString()}.",
        );
    }

    public function updateRole(
        UpdateMemberRoleRequest $request,
        OrganizationMembership $member,
    ): RedirectResponse {
        $user = $request->user();
        abort_if($user === null, 403);

        $this->guardBelongsToActiveOrganization($member);

        $newRole = Role::from($request->string('role')->toString());
        $previous = $member->role;

        // Nobody may grant a role above their own.
        $actingRole = $this->access->roleFor($user, $this->tenant->organization());

        if ($actingRole === null || ! $actingRole->canAssign($newRole)) {
            return back()->withErrors([
                'role' => InvitationException::cannotAssignRole($newRole->label())->getMessage(),
            ]);
        }

        if ($this->wouldRemoveLastOwner($member, $newRole)) {
            return back()->withErrors(['role' => InvitationException::lastOwner()->getMessage()]);
        }

        DB::transaction(function () use ($member, $newRole, $previous, $user): void {
            $member->forceFill(['role' => $newRole->value])->save();

            $this->audit->record(
                action: 'member.role_changed',
                subject: $member,
                description: "Changed {$member->emailAddress()} from {$previous} to {$newRole->value}",
                old: ['role' => $previous],
                new: ['role' => $newRole->value],
                actor: $user,
            );
        });

        // The memo would otherwise answer with the role they had a moment ago.
        $this->access->forget();

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Request $request, OrganizationMembership $member): RedirectResponse
    {
        $this->authorize(Permission::UsersRemove->value);

        $user = $request->user();
        abort_if($user === null, 403);

        $this->guardBelongsToActiveOrganization($member);

        if ($member->user_id === $user->getKey()) {
            return back()->withErrors([
                'member' => 'You cannot remove your own access. Ask another owner to do it.',
            ]);
        }

        if ($this->wouldRemoveLastOwner($member, null)) {
            return back()->withErrors(['member' => InvitationException::lastOwner()->getMessage()]);
        }

        $description = $member->isPendingInvitation()
            ? "Revoked the invitation to {$member->emailAddress()}"
            : "Removed {$member->emailAddress()}";

        DB::transaction(function () use ($member, $description, $user): void {
            /*
             * Audited BEFORE deletion: the audit row references the membership,
             * and recording it afterwards would point at something that no
             * longer exists.
             */
            $this->audit->record(
                action: $member->isPendingInvitation() ? 'member.invitation_revoked' : 'member.removed',
                subject: $member,
                description: $description,
                old: ['email' => $member->emailAddress(), 'role' => $member->role],
                actor: $user,
            );

            $member->delete();
        });

        $this->access->forget();

        return back()->with('success', $description.'.');
    }

    /**
     * An organisation must always keep at least one active owner, or nobody
     * can archive it, manage people, or grant the owner role again.
     */
    private function wouldRemoveLastOwner(OrganizationMembership $member, ?Role $newRole): bool
    {
        if ($member->role !== Role::Owner->value || $newRole === Role::Owner) {
            return false;
        }

        $remainingOwners = OrganizationMembership::query()
            // Scoped explicitly: this model has no global scope, and counting
            // owners across every organisation would answer the wrong question.
            ->where('organization_id', $this->tenant->organization()->getKey())
            ->where('role', Role::Owner->value)
            ->where('status', MembershipStatus::Active)
            ->whereKeyNot($member->getKey())
            ->count();

        return $remainingOwners === 0;
    }

    /**
     * Refuse a membership that belongs to another organisation.
     *
     * Route-model binding resolves `{member}` with no organisation filter, and
     * row-level security deliberately lets a user see their OWN membership of
     * every organisation — so without this an owner of one organisation could
     * pass their membership id from another and change their role there.
     * Permissions are checked against the ACTIVE organisation, so that would
     * be a genuine privilege escalation.
     *
     * 404 rather than 403: whether a membership exists elsewhere is itself
     * information.
     */
    private function guardBelongsToActiveOrganization(OrganizationMembership $member): void
    {
        abort_unless(
            $member->organization_id === $this->tenant->organization()->getKey(),
            404,
        );
    }
}
