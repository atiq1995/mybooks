<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Scopes\OrganizationScope;
use App\Http\Middleware\EstablishTenantContext;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use Closure;

/**
 * The active organisation for the current request, job or command.
 *
 * Registered as a singleton. Two things read it: the global Eloquent scope
 * that constrains every organisation-owned query, and the middleware that
 * sets `app.organization_id` on the PostgreSQL session so row-level security
 * policies can see it too.
 *
 * There is deliberately no "current organisation" global helper and no static
 * state. A queued job runs outside a request and must establish its own
 * context explicitly — silently inheriting whichever organisation happened to
 * be last is exactly how cross-tenant bugs are written.
 *
 * @see OrganizationScope
 * @see EstablishTenantContext
 */
final class TenantContext
{
    private ?Organization $organization = null;

    /**
     * True while an explicitly unscoped block is executing.
     *
     * Not a boolean but a counter, so nested unscoped blocks do not have an
     * inner one re-enabling scoping for the outer.
     */
    private int $unscopedDepth = 0;

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function clear(): void
    {
        $this->organization = null;
    }

    public function has(): bool
    {
        return $this->organization instanceof Organization;
    }

    public function isUnscoped(): bool
    {
        return $this->unscopedDepth > 0;
    }

    public function organization(): Organization
    {
        return $this->organization ?? throw MissingTenantContext::forOrganization();
    }

    public function organizationOrNull(): ?Organization
    {
        return $this->organization;
    }

    /**
     * The organisation id, or null when unscoped or unset.
     *
     * Returns null rather than throwing because the callers that need a
     * nullable answer — the RLS middleware, logging context — genuinely
     * have something sensible to do with one.
     */
    public function id(): ?string
    {
        return $this->organization?->id;
    }

    /**
     * Run a callback with tenant scoping disabled.
     *
     * For genuinely cross-organisation work only: the ledger verifier,
     * backups, platform administration, the migration path. Never reachable
     * from a web request.
     *
     * Note this lifts the *application* scope only. PostgreSQL row-level
     * security still applies unless the caller is also on the owner
     * connection — which is the point: two layers, lifted independently.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runUnscoped(Closure $callback): mixed
    {
        $this->unscopedDepth++;

        try {
            return $callback();
        } finally {
            $this->unscopedDepth--;
        }
    }

    /**
     * Run a callback as a specific organisation, restoring the previous
     * context afterwards even if the callback throws.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runAs(Organization $organization, Closure $callback): mixed
    {
        $previous = $this->organization;
        $this->organization = $organization;

        try {
            return $callback();
        } finally {
            $this->organization = $previous;
        }
    }
}
