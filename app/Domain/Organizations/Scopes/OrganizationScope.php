<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Scopes;

use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on an organisation-owned model to the active
 * organisation.
 *
 * This is the FIRST of the two tenant-isolation layers. The second is
 * PostgreSQL row-level security, which applies underneath this one and does
 * not trust it. Neither layer is sufficient alone, and that is deliberate:
 * this one can be bypassed by a bug, and the other can be bypassed by a
 * misconfigured database role.
 *
 * With no tenant context, this scope THROWS rather than returning everything.
 * A query that does not know which organisation it belongs to is a bug, and
 * the failure mode of "returns all rows" is precisely the one that must never
 * happen in an accounting system.
 *
 * @see BelongsToOrganization
 * @see SECURITY.md §3
 *
 * @implements Scope<Model>
 */
final readonly class OrganizationScope implements Scope
{
    public const string COLUMN = 'organization_id';

    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        // An explicitly unscoped block: the caller has stated the intent.
        if ($context->isUnscoped()) {
            return;
        }

        if (! $context->has()) {
            throw MissingTenantContext::forQuery($model::class);
        }

        $builder->where(
            $model->qualifyColumn(self::COLUMN),
            '=',
            $context->id(),
        );
    }
}
