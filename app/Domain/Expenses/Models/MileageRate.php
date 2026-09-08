<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Models;

use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Tax\Models\Tax;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a kilometre or a mile is worth, and when.
 *
 * Dated, like a tax rate and for the same reason: a rate that changes in
 * April must not restate March's claims. The rate is copied onto the expense
 * line when the claim is made, so this table is only where a default comes
 * from — which means a mistake here cannot reach back into history.
 *
 * The unit is part of the rate, not a display preference. Fifty per kilometre
 * and fifty per mile are different amounts of money, so a business claiming
 * in both keeps two rates.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $unit
 * @property string $rate
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property bool $is_default
 */
final class MileageRate extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = ['name', 'unit', 'rate', 'effective_from', 'effective_to', 'is_default'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_default' => 'boolean',
            'rate' => 'string',
        ];
    }

    /**
     * The rate in force on a date, for a unit.
     *
     * ONE rate, resolved the same way {@see Tax::componentsOn()}
     * resolves a component: the latest applicable version wins. The window is
     * not trusted to be exclusive, because an old row left unclosed is a
     * mistake that should read as untidy rather than as two rates.
     */
    public static function inForce(Carbon $on, string $unit = 'km'): ?self
    {
        return self::query()
            ->where('unit', $unit)
            ->whereDate('effective_from', '<=', $on->toDateString())
            ->where(function (Builder $query) use ($on): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $on->toDateString());
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('effective_to');
    }
}
