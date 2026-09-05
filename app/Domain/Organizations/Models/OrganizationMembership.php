<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Models;

use App\Domain\Organizations\Enums\MembershipStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's membership of an organisation, and the role they hold there.
 *
 * Roles are per-organisation: the same person may be an accountant in one
 * set of books and read-only in another. Nothing about permissions is global.
 *
 * Not using the BelongsToOrganization trait: the organisation switcher has to
 * read a user's memberships ACROSS organisations, before any tenant context
 * exists. The RLS policy expresses that exception explicitly.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string $role
 * @property MembershipStatus $status
 * @property list<string>|null $granted_permissions
 * @property list<string>|null $revoked_permissions
 * @property Carbon|null $joined_at
 */
final class OrganizationMembership extends Model
{
    use HasUuids;

    /*
     * Memberships are created by domain Actions (and factories), never from a
     * request array — so the identifying columns are fillable here. The
     * invitation token hash is not: it is set with forceFill() by the action
     * that mints it, and nothing else may write it.
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
        'status',
        'granted_permissions',
        'revoked_permissions',
        'invited_by',
        'invited_at',
        'joined_at',
        'invitation_expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'granted_permissions' => 'array',
            'revoked_permissions' => 'array',
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'last_accessed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }
}
