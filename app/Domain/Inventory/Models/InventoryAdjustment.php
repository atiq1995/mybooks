<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A change to stock that no sale or purchase explains.
 *
 * A stocktake that disagreed, a breakage, a write-down, or the opening
 * position brought in from whatever the business used before.
 *
 * It is a DOCUMENT with an approval step rather than a direct edit, because
 * it is the one route by which stock changes without a document behind it —
 * which makes it the one route by which a shortfall could be made to
 * disappear. §8 says it posts on approval, and the reason for it is
 * mandatory.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property Carbon $adjustment_date
 * @property string $warehouse_id
 * @property string $kind
 * @property string $account_id
 * @property string $reason
 * @property string|null $notes
 * @property string $status
 * @property string $total_value
 * @property string|null $journal_entry_id
 * @property string|null $void_journal_entry_id
 * @property Carbon|null $approved_at
 * @property Carbon|null $voided_at
 */
final class InventoryAdjustment extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'adjustment_date' => 'date',
            'approved_at' => 'datetime',
            'voided_at' => 'datetime',
            'total_value' => 'string',
        ];
    }

    /**
     * @return HasMany<InventoryAdjustmentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentLine::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isVoided(): bool
    {
        return $this->status === 'void';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Draft',
            'approved' => 'Approved',
            'void' => 'Voided',
            default => ucfirst($this->status),
        };
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'revaluation' => 'Revaluation',
            'opening' => 'Opening stock',
            default => 'Quantity adjustment',
        };
    }
}
