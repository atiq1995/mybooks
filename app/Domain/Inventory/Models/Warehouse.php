<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Organizations\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Where stock physically sits.
 *
 * Every organisation that tracks anything has at least one, even if nobody
 * ever thinks about it. Making the concept optional would mean a nullable
 * location on every movement and a decision, in every report, about what null
 * meant.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string|null $address
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $archived_at
 */
final class Warehouse extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'code',
        'name',
        'address',
        'is_default',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<StockLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->archived_at === null;
    }

    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }
}
