<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Organizations\Enums\MembershipStatus;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant context in BOTH isolation layers.
 *
 * This is deliberately one middleware rather than two, because the two halves
 * are order-dependent in a way that is easy to get wrong and silent when it
 * goes wrong:
 *
 *   1. `app.user_id` must reach PostgreSQL FIRST, because the RLS policy on
 *      `organizations` decides visibility by membership — so the very query
 *      that resolves the organisation is itself filtered by RLS, and would
 *      return nothing if the user were not yet published.
 *   2. Only then can the organisation be resolved.
 *   3. `app.organization_id` is published once it is known.
 *
 * Split across two middleware, an innocuous reordering breaks tenant
 * resolution in a way that looks like "the user has no organisations".
 *
 * The organisation id comes from the SESSION, never from the URL. If a caller
 * could name the organisation, isolation would depend on validating that
 * parameter perfectly on every one of several hundred routes.
 *
 * @see SECURITY.md §3
 * @see database/migrations/2026_01_01_000400_enable_row_level_security.php
 */
final readonly class EstablishTenantContext
{
    public const string SESSION_KEY = 'active_organization_id';

    public function __construct(
        private TenantContext $context,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Start from nothing. Nothing legitimate sets tenant context before
        // this middleware in a web request, so anything already present is a
        // leak from earlier in the process — and in a long-lived worker that
        // would be someone else's organisation.
        $this->context->clear();

        // Step 1 — publish the user, so membership-based RLS can see them.
        $this->publish(userId: $user?->id, organizationId: null);

        try {
            if ($user === null) {
                return $next($request);
            }

            // Step 2 — resolve the organisation.
            $organizationId = $request->session()->get(self::SESSION_KEY)
                ?? $user->last_organization_id;

            if (is_string($organizationId) && $organizationId !== '') {
                /*
                 * Membership is re-verified on EVERY request, not only when
                 * switching. Someone removed from an organisation mid-session
                 * loses access immediately, rather than whenever they next
                 * happen to switch.
                 */
                $organization = Organization::query()
                    ->whereKey($organizationId)
                    ->whereNull('archived_at')
                    ->whereHas('memberships', function ($query) use ($user): void {
                        $query
                            ->where('user_id', $user->getAuthIdentifier())
                            ->where('status', MembershipStatus::Active->value);
                    })
                    ->first();

                if ($organization === null) {
                    // Stale or revoked. Clear it rather than failing: the user
                    // lands on the organisation chooser, not an error page.
                    $request->session()->forget(self::SESSION_KEY);
                } else {
                    // Step 3 — publish the organisation to both layers.
                    $this->context->set($organization);
                    $request->session()->put(self::SESSION_KEY, $organization->getKey());

                    $this->publish(
                        userId: $user->id,
                        organizationId: $organization->id,
                    );
                }
            }

            return $next($request);
        } finally {
            // Always, including on an exception. An error response must never
            // leave a connection carrying somebody's organisation id.
            $this->publish(userId: null, organizationId: null);
        }
    }

    /**
     * Publish the context to PostgreSQL for row-level security.
     *
     * Session scope rather than `SET LOCAL`: Laravel does not wrap requests in
     * a transaction, so a LOCAL setting would be discarded before the first
     * query ran. The connection is per-request (FrankenPHP classic mode, no
     * persistent PDO), and the `finally` above clears these regardless — which
     * is what would stop one request's organisation leaking into the next if
     * connection pooling were ever introduced.
     *
     * Values are bound, never interpolated.
     */
    private function publish(?string $userId, ?string $organizationId): void
    {
        $this->connection->statement(
            'select set_config(?, ?, false), set_config(?, ?, false)',
            [
                'app.user_id', $userId ?? '',
                'app.organization_id', $organizationId ?? '',
            ],
        );
    }
}
