<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One audited event.
 *
 * Rows are append-only — a database trigger refuses UPDATE and DELETE, and so
 * does this model. Written in the same transaction as the change they record.
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string|null $user_id
 * @property string $action
 * @property string|null $auditable_type
 * @property string|null $auditable_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property Carbon $created_at
 */
final class AuditLog extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    // Append-only. `created_at` is set by the database default.
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'actor_name',
        'actor_email',
        'actor_type',
        'action',
        'auditable_type',
        'auditable_id',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'request_id',
        'channel',
        'amount',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The database refuses these too; failing here first gives a clearer
        // error than a PostgreSQL restrict_violation.
        self::updating(fn (): never => throw new \LogicException(
            'Audit records are append-only and cannot be updated.'
        ));

        self::deleting(fn (): never => throw new \LogicException(
            'Audit records are append-only and cannot be deleted.'
        ));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
