<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organizations\Enums\MembershipStatus;
use App\Domain\Organizations\Exceptions\InvitationException;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Turns an invitation into a membership.
 *
 * Two entry points, because open registration is off and an invitation is the
 * only route to an account here:
 *
 *   accept()            — the invitee already has an account and is signed in
 *   acceptAndRegister() — the invitee has no account, and creates one from the
 *                         invitation itself
 *
 * The token is looked up by hash, never stored or logged in the clear, and is
 * cleared on acceptance so a link works exactly once.
 *
 * Everything here runs for a visitor with NO session and NO tenant context,
 * which is what makes it awkward: row-level security hides both the invitation
 * and the organisation that issued it. Possession of the token is the
 * authorisation, so the token's hash is published as a database setting for
 * precisely as long as the lookup takes, and no longer.
 */
final readonly class AcceptInvitation
{
    public function __construct(
        private TenantContext $tenant,
        private AuditRecorder $audit,
    ) {}

    /**
     * Find a pending, unexpired invitation by its plaintext token, with its
     * organisation loaded.
     */
    public function find(string $token): OrganizationMembership
    {
        $hash = hash('sha256', $token);

        return $this->withInvitationLookup($hash, function () use ($hash): OrganizationMembership {
            $membership = $this->tenant->runUnscoped(
                fn (): ?OrganizationMembership => OrganizationMembership::query()
                    ->where('invitation_token_hash', $hash)
                    ->first(),
            );

            if ($membership === null) {
                throw InvitationException::notFound();
            }

            if (! $membership->isPendingInvitation()) {
                throw InvitationException::alreadyAccepted();
            }

            if ($membership->invitationHasExpired()) {
                throw InvitationException::expired();
            }

            /*
             * Load the organisation that issued the invitation.
             *
             * `organizations` is only readable for the active organisation or
             * one the visitor is already a member of — and an invitee is
             * neither. The token authorises knowing who invited you, so the
             * organisation id is published just long enough to read it, and is
             * cleared with the lookup setting on the way out.
             *
             * setRelation keeps the loaded model on the instance, so callers
             * still have it after the settings are gone.
             */
            $this->publishOrganization($membership->organization_id);

            $organization = $this->tenant->runUnscoped(
                fn (): ?Organization => Organization::query()->find($membership->organization_id),
            );

            if ($organization === null) {
                throw InvitationException::notFound();
            }

            $membership->setRelation('organization', $organization);

            return $membership;
        });
    }

    /**
     * Accept as an existing, signed-in user.
     */
    public function accept(string $token, User $user): OrganizationMembership
    {
        $membership = $this->find($token);

        /*
         * The invitation belongs to an address, not to whoever holds the link.
         * Accepting while signed in as somebody else would silently attach the
         * wrong account to the organisation.
         */
        $invitedEmail = mb_strtolower((string) $membership->invited_email);

        if ($invitedEmail !== '' && mb_strtolower($user->email) !== $invitedEmail) {
            throw InvitationException::wrongAccount($membership->invited_email ?? '');
        }

        return $this->activate($membership, $user);
    }

    /**
     * Accept by creating the account the invitation was sent to.
     *
     * This is the only way an account comes into existence in a default
     * deployment, so the address is taken from the INVITATION rather than from
     * user input — otherwise the invitation would be an open registration form
     * wearing a disguise.
     */
    public function acceptAndRegister(
        string $token,
        string $name,
        string $password,
    ): OrganizationMembership {
        $membership = $this->find($token);
        $email = mb_strtolower((string) $membership->invited_email);

        if ($email === '') {
            throw InvitationException::notFound();
        }

        if (User::query()->where('email', $email)->exists()) {
            // The account appeared between the link being sent and used; they
            // should sign in and accept rather than create a second one.
            throw InvitationException::alreadyAccepted();
        }

        /*
         * ONE transaction around both halves. Creating the account separately
         * would leave an orphaned user — able to sign in, belonging to nothing
         * — whenever activation failed.
         */
        return DB::transaction(function () use ($membership, $email, $name, $password): OrganizationMembership {
            /*
             * forceFill, not mass assignment: `email` and `email_verified_at`
             * are deliberately absent from User::$fillable, because nothing
             * arriving in a request should set either. Both come from the
             * invitation here, which is exactly why this is safe.
             */
            $user = new User;

            $user->forceFill([
                'name' => trim($name),
                'email' => $email,
                // Hashed by the model's 'password' => 'hashed' cast.
                'password' => $password,
                // The invitation reached this address, which is the same proof
                // a verification email provides. Asking again would be theatre.
                'email_verified_at' => now(),
                // Start them in the organisation's timezone; they can change it.
                'timezone' => $membership->organization->timezone ?? 'UTC',
            ])->save();

            return $this->activate($membership, $user);
        });
    }

    private function activate(OrganizationMembership $membership, User $user): OrganizationMembership
    {
        $organization = $membership->organization;

        if (! $organization instanceof Organization) {
            throw InvitationException::notFound();
        }

        /*
         * Run the activation INSIDE the organisation being joined.
         *
         * The write clears the token hash, so the row it produces no longer
         * matches the lookup policy that made it visible. Establishing real
         * tenant context first means the ordinary
         * `organization_id = app_current_organization_id()` policy covers both
         * this update and the audit row that follows it.
         */
        return $this->tenant->runAs($organization, fn (): OrganizationMembership => DB::transaction(
            function () use ($membership, $user, $organization): OrganizationMembership {
                $membership->forceFill([
                    'user_id' => $user->getKey(),
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                    // One use only.
                    'invitation_token_hash' => null,
                    'invitation_expires_at' => null,
                ])->save();

                $user->forceFill(['last_organization_id' => $organization->getKey()])->save();

                $this->audit->record(
                    action: 'member.joined',
                    subject: $membership,
                    description: "{$user->name} joined {$organization->name} as {$membership->role}",
                    new: ['user_id' => $user->getKey(), 'role' => $membership->role],
                    actor: $user,
                );

                return $membership;
            },
        ));
    }

    /**
     * Run a callback with the invitation-lookup setting published, then clear
     * it — and the organisation setting with it.
     *
     * The `finally` matters: a failure part-way through must not leave a
     * connection able to read an invitation, or an organisation, that the
     * visitor has no other claim to.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withInvitationLookup(string $hash, Closure $callback): mixed
    {
        DB::statement("select set_config('app.invitation_token_hash', ?, false)", [$hash]);

        try {
            return $callback();
        } finally {
            DB::statement(<<<'SQL'
                select set_config('app.invitation_token_hash', '', false),
                       set_config('app.organization_id', '', false)
            SQL);
        }
    }

    private function publishOrganization(string $organizationId): void
    {
        DB::statement(
            "select set_config('app.organization_id', ?, false)",
            [$organizationId],
        );
    }
}
