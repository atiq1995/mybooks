<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when organisation-scoped work is attempted with no organisation set.
 *
 * This is a programming error, never a user error. It means a query that must
 * be constrained to one organisation was about to run unconstrained — so it
 * fails loudly rather than returning every organisation's rows.
 */
final class MissingTenantContext extends RuntimeException
{
    public static function forOrganization(): self
    {
        return new self(
            'No organisation in the tenant context. A request should have passed through '.
            'ResolveOrganization middleware; a job or command must establish context with '.
            'TenantContext::runAs(), or opt out deliberately with TenantContext::runUnscoped().'
        );
    }

    public static function forQuery(string $model): self
    {
        return new self(
            "Refusing to query [{$model}] without an organisation. This model is ".
            'organisation-scoped, so an unconstrained query would cross tenants. '.
            'Establish context with TenantContext::runAs(), or state the intent '.
            'explicitly with TenantContext::runUnscoped().'
        );
    }
}
