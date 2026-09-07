<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Organizations\Concerns\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One side of a posted journal entry.
 *
 * Amounts are exposed as decimal STRINGS, not money objects: a line's
 * currency belongs to its entry, and handing out a Money here would need the
 * entry loaded to be meaningful. Callers that want money ask the entry.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $journal_entry_id
 * @property int $line_no
 * @property string $account_id
 * @property string $debit
 * @property string $credit
 * @property string $debit_base
 * @property string $credit_base
 * @property string|null $memo
 * @property string|null $contact_id
 */
final class JournalLine extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    // Set by the ledger service only.
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException(
            'The ledger is append-only: a journal line cannot be deleted. '.
            'Reverse the entry instead. See ACCOUNTING_RULES.md I4.'
        ));

        self::updating(fn (): never => throw new LogicException(
            'The ledger is append-only: a journal line cannot be edited. '.
            'Reverse the entry instead. See ACCOUNTING_RULES.md I4.'
        ));
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isDebit(): bool
    {
        return BigDecimal::of($this->debit)->isPositive();
    }

    /** The amount, whichever side it is on. */
    public function amount(): string
    {
        return $this->isDebit() ? $this->debit : $this->credit;
    }
}
