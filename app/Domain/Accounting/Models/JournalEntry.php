<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\EntryStatus;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A posted journal entry.
 *
 * Append-only. The model refuses edits and deletes, and a database trigger
 * refuses them again — corrections are reversing entries, so history is never
 * rewritten.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $entry_no
 * @property Carbon $entry_date
 * @property string $fiscal_period_id
 * @property string $source_type
 * @property string|null $source_id
 * @property string $source_purpose
 * @property string $currency
 * @property string $base_currency
 * @property string $exchange_rate
 * @property string $total_debit
 * @property string $total_credit
 * @property EntryStatus $status
 * @property string|null $reverses_entry_id
 * @property string|null $memo
 * @property Carbon $posted_at
 */
final class JournalEntry extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    /*
     * Nothing here is fillable. Every column is set by the ledger service with
     * forceFill — an entry must never be assembled from request input.
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'status' => EntryStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException(
            'The ledger is append-only: a journal entry cannot be deleted. '.
            'Reverse it instead. See ACCOUNTING_RULES.md I4.'
        ));

        self::updating(function (self $entry): void {
            /*
             * The single permitted change: marking an entry reversed. Anything
             * else means somebody is trying to edit history.
             */
            $permitted = ['status', 'reverses_entry_id', 'updated_at'];
            $changed = array_keys($entry->getDirty());

            if (array_diff($changed, $permitted) !== []) {
                throw new LogicException(
                    'A posted journal entry cannot be edited ('.implode(', ', $changed).'). '.
                    'Reverse it and post a correct one. See ACCOUNTING_RULES.md I4.'
                );
            }
        });
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<FiscalPeriod, $this>
     */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * The entry this one reverses, if it is a reversal.
     *
     * @return BelongsTo<self, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /**
     * The reversal of this entry, if it has been reversed.
     *
     * @return HasMany<self, $this>
     */
    public function reversedBy(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_entry_id');
    }

    public function isReversed(): bool
    {
        return $this->status === EntryStatus::Reversed;
    }

    public function isReversal(): bool
    {
        return $this->reverses_entry_id !== null;
    }
}
