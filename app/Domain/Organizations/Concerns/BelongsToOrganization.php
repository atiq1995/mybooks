<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Concerns;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Scopes\OrganizationScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Marks a model as owned by an organisation.
 *
 * Applying this trait does three things:
 *
 *   1. Every query is constrained to the active organisation.
 *   2. `organization_id` is filled automatically on create, so no caller can
 *      forget it and no caller can spoof it.
 *   3. `organization_id` becomes immutable — moving a record between
 *      organisations is not an update, and pretending it is would silently
 *      relocate its journal entries away from the books that reference them.
 *
 * Every table backing a model using this trait must also carry a row-level
 * security policy. The trait alone is one layer; the migration adds the
 * second.
 *
 * @property string $organization_id
 *
 * @phpstan-require-extends Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (self $model): void {
            if ($model->getAttribute(OrganizationScope::COLUMN) !== null) {
                return;
            }

            $context = app(TenantContext::class);

            // Unscoped creation must be explicit about the owner: a record
            // created with no organisation at all would be invisible to
            // every query, including the one that comes looking for it.
            if ($context->isUnscoped() && ! $context->has()) {
                throw new RuntimeException(
                    'Cannot create '.static::class.' in an unscoped context without an '.
                    'explicit organization_id. Set it, or use TenantContext::runAs().'
                );
            }

            $model->setAttribute(OrganizationScope::COLUMN, $context->organization()->getKey());
        });

        static::updating(function (self $model): void {
            if (! $model->isDirty(OrganizationScope::COLUMN)) {
                return;
            }

            throw new RuntimeException(
                'organization_id is immutable on '.static::class.'. A record cannot be '.
                'moved between organisations; its ledger history belongs to the books it '.
                'was posted in.'
            );
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Fields that must never be mass-assigned, whatever `$fillable` says.
     *
     * @return list<string>
     */
    public function guardedTenantAttributes(): array
    {
        return [OrganizationScope::COLUMN];
    }
}
