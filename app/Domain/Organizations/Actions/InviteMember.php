<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Actions;

use App\Domain\Access\AccessControl;
use App\Domain\Access\Enums\Role;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organizations\Enums\MembershipStatus;
use App\Domain\Organizations\Exceptions\InvitationException;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Notifications\MemberInvited;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Invites someone into an organisation.
 *
 * Open registration is off, so an invitation is the only route to an account
 * in this deployment. That makes the token a credential: it is generated
 * long, stored only as a SHA-256 hash, and emailed once. A leaked database
 * backup hands out no working invitations.
 *
 * Re-inviting a pending invitation issues a fresh token and invalidates the
 * old one, which is what "resend" has to mean if the first email went astray.
 */
final readonly class InviteMember
{
    public function __construct(
        private AccessControl $access,
        private AuditRecorder $audit,
    ) {}

    /**
     * @return array{membership: OrganizationMembership, token: string}
     */
    public function handle(
        Organization $organization,
        string $email,
        Role $role,
        User $invitedBy,
    ): array {
        $email = mb_strtolower(trim($email));

        $this->guard($organization, $email, $role, $invitedBy);

        // Long enough that guessing is not a strategy; the column stores only
        // its hash.
        $token = Str::random(48);

        $membership = DB::transaction(function () use (
            $organization,
            $email,
            $role,
            $invitedBy,
            $token,
        ): OrganizationMembership {
            $invitee = $this->findUserByEmail($email);

            // Reissue onto the existing pending invitation if there is one, so
            // "resend" replaces the old token rather than creating a duplicate.
            $membership = $this->existingMembership($organization, $email)
                ?? new OrganizationMembership;

            /*
             * forceFill, not mass assignment: `invitation_token_hash` is
             * deliberately absent from $fillable because it is a credential,
             * and nothing reaching this application from a request should ever
             * be able to set it.
             */
            $membership->forceFill([
                'organization_id' => $organization->getKey(),
                // An invitee without an account yet has no user_id; the email
                // on the invitation is what links them.
                'user_id' => $invitee?->getKey(),
                'invited_email' => $email,
                'role' => $role->value,
                'status' => MembershipStatus::Invited,
                'invited_by' => $invitedBy->getKey(),
                'invited_at' => now(),
                'invitation_token_hash' => hash('sha256', $token),
                'invitation_expires_at' => now()->addDays(
                    config()->integer('my-books.invitations.expire_after_days', 7),
                ),
            ])->save();

            $this->audit->record(
                action: 'member.invited',
                subject: $membership,
                description: "Invited {$email} as {$role->label()}",
                new: ['email' => $email, 'role' => $role->value],
                actor: $invitedBy,
            );

            return $membership;
        });

        /*
         * Sent AFTER the transaction commits. An email referring to an
         * invitation that was rolled back is worse than a missing email — the
         * recipient would follow a link that goes nowhere.
         */
        Notification::route('mail', $email)->notify(
            new MemberInvited($organization, $role, $invitedBy, $token, $email),
        );

        return ['membership' => $membership, 'token' => $token];
    }

    private function guard(
        Organization $organization,
        string $email,
        Role $role,
        User $invitedBy,
    ): void {
        if (mb_strtolower($invitedBy->email) === $email) {
            throw InvitationException::cannotInviteSelf();
        }

        /*
         * Nobody may grant a role above their own — otherwise an administrator
         * could promote themselves to owner by inviting a second account.
         */
        $inviterRole = $this->access->roleFor($invitedBy, $organization);

        if ($inviterRole === null || ! $inviterRole->canAssign($role)) {
            throw InvitationException::cannotAssignRole($role->label());
        }

        $existing = $this->existingMembership($organization, $email);

        // A pending invitation may be reissued; an accepted one may not.
        if ($existing !== null && $existing->status !== MembershipStatus::Invited) {
            throw InvitationException::alreadyAMember($organization->name);
        }
    }

    /**
     * Any membership in this organisation for the given address — whether it
     * is a pending invitation or an accepted member.
     */
    private function existingMembership(
        Organization $organization,
        string $email,
    ): ?OrganizationMembership {
        return OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where(function ($query) use ($email): void {
                $query->where('invited_email', $email)
                    ->orWhereHas('user', fn ($q) => $q->where('email', $email));
            })
            ->first();
    }

    private function findUserByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }
}
