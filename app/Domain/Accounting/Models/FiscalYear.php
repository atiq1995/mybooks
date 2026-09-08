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
 * A financial year.
 *
 * The label is not a calendar year: Pakistan's 2026-27 tax year runs from
 * July 2026 to June 2027, and the label says so.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $label
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property PeriodStatus $status
 * @property string|null $closing_entry_id
 */
final class FiscalYear extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = ['label', 'starts_on', 'ends_on'];

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
        ];
    }

    /**
     * The year-end entry that moved net income to retained earnings.
     *
     * @return BelongsTo<JournalEntry, $this>
     */
    public function closingEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'closing_entry_id');
    }

    /**
     * @return HasMany<FiscalPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class)->orderBy('sequence');
    }

    public function isClosed(): bool
    {
        return $this->status !== PeriodStatus::Open;
    }
}
