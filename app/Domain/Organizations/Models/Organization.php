<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Models;

use App\Models\User;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An organisation — one set of books, and the tenant boundary of the system.
 *
 * This model is deliberately NOT organisation-scoped. It *is* the scope.
 * Access is governed by membership: see the RLS policy in
 * 2026_01_01_000400_enable_row_level_security.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $legal_name
 * @property string $base_currency
 * @property string $country_code
 * @property string $jurisdiction
 * @property int $fiscal_year_start_month
 * @property string $rounding_mode
 * @property string $timezone
 * @property string $locale
 * @property string $date_format
 * @property array<string, mixed>|null $address
 * @property Carbon|null $onboarding_completed_at
 * @property Carbon|null $archived_at
 */
final class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'legal_name',
        'base_currency',
        'country_code',
        'jurisdiction',
        'fiscal_year_start_month',
        'rounding_mode',
        'tax_registration_number',
        'sales_tax_registration_number',
        'business_registration_number',
        'timezone',
        'locale',
        'date_format',
        'address',
        'phone',
        'email',
        'website',
        'logo_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address' => 'array',
            'fiscal_year_start_month' => 'integer',
            'onboarding_completed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OrganizationMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')
            ->withPivot(['role', 'status', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Until onboarding finishes, an organisation has no usable chart of
     * accounts — so the application routes its users back into the wizard
     * rather than showing them an accounting UI that cannot work.
     */
    public function hasCompletedOnboarding(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * The calendar months of a financial year, in order, starting from the
     * organisation's own fiscal year start.
     *
     * Pakistan's tax year runs July to June, so this is very often
     * [7, 8, ..., 12, 1, ..., 6] rather than a calendar year.
     *
     * @return list<int>
     */
    public function fiscalMonths(): array
    {
        return array_map(
            fn (int $offset): int => (($this->fiscal_year_start_month - 1 + $offset) % 12) + 1,
            range(0, 11),
        );
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
