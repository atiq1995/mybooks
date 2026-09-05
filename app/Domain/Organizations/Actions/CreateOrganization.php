<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Actions;

use App\Domain\Access\Enums\Role;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organizations\Data\NewOrganizationData;
use App\Domain\Organizations\Enums\MembershipStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates an organisation and makes its creator the owner.
 *
 * One transaction: the organisation, the owner's membership, the creator's
 * "last organisation" pointer, and the audit row all commit together. A
 * half-created organisation — one with no members — would be permanently
 * unreachable, since access is granted by membership.
 *
 * The organisation is left with `onboarding_completed_at` null. Until the
 * wizard finishes it has no chart of accounts and cannot post anything, so
 * the application routes its users back into onboarding rather than into an
 * accounting UI that cannot work.
 */
final readonly class CreateOrganization
{
    public function __construct(
        private TenantContext $tenant,
        private AuditRecorder $audit,
    ) {}

    public function handle(NewOrganizationData $data, User $creator): Organization
    {
        return DB::transaction(function () use ($data, $creator): Organization {
            $organization = new Organization;

            $organization->forceFill([
                'name' => $data->name,
                'legal_name' => $data->legalName,
                'slug' => $this->uniqueSlug($data->name),
                'base_currency' => $data->baseCurrency,
                'country_code' => $data->countryCode,
                'jurisdiction' => $data->jurisdiction,
                'fiscal_year_start_month' => $data->fiscalYearStartMonth,
                'timezone' => $data->timezone,
                'locale' => $data->locale,
                'rounding_mode' => $data->roundingMode,
                'created_by' => $creator->getKey(),
                // Deliberately null: onboarding has not run yet.
                'onboarding_completed_at' => null,
            ])->save();

            OrganizationMembership::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $creator->getKey(),
                'role' => Role::Owner->value,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);

            $creator->forceFill(['last_organization_id' => $organization->getKey()])->save();

            /*
             * The audit row is organisation-scoped, so the context has to point
             * at the new organisation before it can be written. Setting it here
             * also means the rest of the request already operates inside the
             * organisation that was just created.
             */
            $this->tenant->set($organization);

            $this->audit->record(
                action: 'organization.created',
                subject: $organization,
                description: "Created {$organization->name}",
                new: [
                    'name' => $organization->name,
                    'base_currency' => $organization->base_currency,
                    'country_code' => $organization->country_code,
                    'fiscal_year_start_month' => $organization->fiscal_year_start_month,
                ],
                actor: $creator,
            );

            return $organization;
        });
    }

    /**
     * A readable, unique slug.
     *
     * Slugs are the public handle for an organisation, so they are derived
     * from the name — but two businesses may share a name, and the column is
     * unique. Falls back to a short random suffix rather than an incrementing
     * counter, which would leak how many similarly-named organisations exist.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'organisation';
        }

        $base = Str::limit($base, 40, '');

        if (! $this->slugTaken($base)) {
            return $base;
        }

        do {
            $candidate = $base.'-'.Str::lower(Str::random(5));
        } while ($this->slugTaken($candidate));

        return $candidate;
    }

    private function slugTaken(string $slug): bool
    {
        // Unscoped: uniqueness is global, and the caller has no organisation
        // context at this point in any case.
        return $this->tenant->runUnscoped(
            fn (): bool => Organization::query()->where('slug', $slug)->exists(),
        );
    }
}
