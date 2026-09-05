<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * A person who can sign in.
 *
 * Users are PLATFORM-level, not organisation-level: one person may hold
 * different roles in several organisations, so nothing about a user's
 * permissions lives here. Membership and role live in
 * {@see OrganizationMembership}.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $last_organization_id
 * @property string $theme
 * @property string $density
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $suspended_at
 */
final class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'timezone',
        'theme',
        'density',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'suspended_at' => 'datetime',

            // argon2id, configured in config/hashing.php. SECURITY.md §2.
            'password' => 'hashed',

            // Encrypted at rest: a database backup must not hand out working
            // second factors.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
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
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_memberships')
            ->withPivot(['role', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * A suspended user keeps their history and their audit trail; they simply
     * cannot sign in. Deleting them would orphan the record of what they did.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function membershipFor(Organization $organization): ?OrganizationMembership
    {
        return $this->memberships()
            ->where('organization_id', $organization->getKey())
            ->first();
    }
}
