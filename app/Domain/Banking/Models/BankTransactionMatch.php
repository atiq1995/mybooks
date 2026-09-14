<?php

declare(strict_types=1);

namespace App\Domain\Banking\Models;

use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "This statement line is that journal line."
 *
 * The only join between the bank's record and ours, and it is always written
 * by a person: `matched_by` is who said so and `matched_at` is when. A match
 * posts nothing — both sides existed already — which is exactly why it can be
 * made by somebody with `banking.reconcile` and no posting permission.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $bank_account_id
 * @property string $statement_line_id
 * @property string $journal_line_id
 * @property string $amount
 * @property string $origin
 * @property int|null $confidence
 * @property string|null $reconciliation_id
 * @property string|null $note
 * @property string|null $matched_by
 * @property Carbon $matched_at
 */
final class BankTransactionMatch extends Model
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
            'matched_at' => 'datetime',
            'amount' => 'string',
            'confidence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BankStatementLine, $this>
     */
    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'statement_line_id');
    }

    /**
     * @return BelongsTo<JournalLine, $this>
     */
    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class, 'journal_line_id');
    }

    /**
     * @return BelongsTo<BankReconciliation, $this>
     */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'reconciliation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    public function isLocked(): bool
    {
        return $this->reconciliation_id !== null;
    }
}
