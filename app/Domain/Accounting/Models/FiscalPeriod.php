<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One period within a financial year — usually a month.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $fiscal_year_id
 * @property int $sequence
 * @property string $label
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property PeriodStatus $status
 * @property Carbon|null $closed_at
 */
final class FiscalPeriod extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = ['sequence', 'label', 'starts_on', 'ends_on'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => PeriodStatus::class,
            'closed_at' => 'datetime',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function acceptsPostings(): bool
    {
        return $this->status->acceptsPostings();
    }

    public function covers(Carbon $date): bool
    {
        return $date->betweenIncluded($this->starts_on, $this->ends_on);
    }
}
