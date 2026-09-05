<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Access\AccessControl;
use App\Domain\Access\Enums\Permission;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registers one Gate per {@see Permission}, so the whole application can ask
 * `$user->can('accounting.post')` — in a FormRequest, a Policy, a controller,
 * or a React prop — and get the same answer from the same place.
 *
 * Gates resolve against the ACTIVE ORGANISATION from the tenant context, never
 * against an organisation passed in by the caller. A permission check that let
 * the caller name the organisation would be a permission check that could be
 * pointed at the wrong one.
 *
 * @see AccessControl
 * @see SECURITY.md section 4
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Memoised per request — see the class docblock for why it is not
        // cached beyond that.
        $this->app->singleton(AccessControl::class);
    }

    public function boot(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user): Response => $this->decide($user, $permission),
            );
        }
    }

    private function decide(User $user, Permission $permission): Response
    {
        // A suspended account keeps its history but loses every capability.
        if ($user->isSuspended()) {
            return Response::denyWithStatus(403, 'This account is suspended.');
        }

        $organization = $this->app->make(TenantContext::class)->organizationOrNull();

        if (! $organization instanceof Organization) {
            return Response::denyWithStatus(
                403,
                'No organisation is active for this request.',
            );
        }

        if ($this->app->make(AccessControl::class)->denies($user, $organization, $permission)) {
            return Response::denyWithStatus(403, 'Your role does not permit this.');
        }

        /*
         * Two-factor gate for the consequential permissions — posting to the
         * ledger, moving money, changing who has access.
         *
         * Enforced AFTER the role check, so someone who lacks the permission
         * is told that, rather than being sent to enrol in two-factor for a
         * capability they would still not have.
         */
        if ($permission->requiresTwoFactor()
            && $this->twoFactorEnforced()
            && ! $user->hasTwoFactorEnabled()
        ) {
            return Response::denyWithStatus(
                403,
                'Two-factor authentication is required for this action. Enable it in your security settings.',
            );
        }

        return Response::allow();
    }

    /**
     * Whether the two-factor requirement is active in this environment.
     *
     * Off in local development by default, so a freshly seeded account can
     * exercise the application without first enrolling an authenticator. On
     * everywhere else. See config/my-books.php.
     */
    private function twoFactorEnforced(): bool
    {
        return config()->boolean('my-books.security.enforce_two_factor');
    }
}
