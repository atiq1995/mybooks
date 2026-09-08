<?php

declare(strict_types=1);

namespace App\Domain\Tax\Models;

use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Tax\Enums\TaxAppliesTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A tax a user can pick on a line.
 *
 * The rate lives on its components, not here, because one tax can be several
 * — a provincial sales tax plus a further tax for an unregistered buyer, each
 * landing in its own liability account and each reportable separately.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $code
 * @property TaxAppliesTo $applies_to
 * @property bool $is_inclusive_default
 * @property bool $is_zero_rated
 * @property bool $is_exempt
 * @property bool $is_active
 * @property Carbon|null $archived_at
 */
final class Tax extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'name',
        'code',
        'applies_to',
        'is_inclusive_default',
        'is_zero_rated',
        'is_exempt',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'applies_to' => TaxAppliesTo::class,
            'is_inclusive_default' => 'boolean',
            'is_zero_rated' => 'boolean',
            'is_exempt' => 'boolean',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<TaxComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(TaxComponent::class)->orderBy('sequence');
    }

    /**
     * The components in force on a given date.
     *
     * Not simply "the components": a rate that changes on 1 July must not
     * touch June's invoices, so the version is resolved against the
     * document's own date.
     *
     * @return list<TaxComponent>
     */
    public function componentsOn(Carbon $date): array
    {
        $on = $date->toDateString();

        return array_values(
            $this->components()
                ->whereDate('effective_from', '<=', $on)
                ->where(function (Builder $query) use ($on): void {
                    $query->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $on);
                })
                ->orderBy('sequence')
                ->get()
                ->all(),
        );
    }

    /**
     * Whether this tax charges anything.
     *
     * True for an exempt tax and for one whose only components are 0% — and
     * those are NOT the same thing on a return, which is why both flags
     * exist rather than one.
     */
    public function isNil(): bool
    {
        return $this->is_exempt || $this->is_zero_rated;
    }

    public function acceptsUse(): bool
    {
        return $this->is_active && $this->archived_at === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSales(Builder $query): Builder
    {
        return $query->whereIn('applies_to', [TaxAppliesTo::Sales->value, TaxAppliesTo::Both->value]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithholding(Builder $query): Builder
    {
        return $query->where('applies_to', TaxAppliesTo::Withholding->value);
    }
}
