<?php

declare(strict_types=1);

namespace App\Domain\Banking\Models;

use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One reconciliation of one account over one period.
 *
 * Four figures, and the relationship between them is the entire exercise:
 *
 *   opening      what the bank said the balance was at the start
 *   closing      what the bank says it is at the end
 *   cleared      opening, plus everything we have matched in between
 *   difference   closing − cleared, which must be zero to complete
 *
 * A difference is not an error to be written off. It is unfinished work: a
 * payment we recorded that never reached the bank, a charge the bank made
 * that we have not recorded, or a match that is wrong.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $bank_account_id
 * @property string $number
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $opening_balance
 * @property string $closing_balance
 * @property string $cleared_balance
 * @property string $difference
 * @property ReconciliationStatus $status
 * @property string|null $notes
 * @property Carbon|null $completed_at
 * @property string|null $completed_by
 */
final class BankReconciliation extends Model
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
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => ReconciliationStatus::class,
            'completed_at' => 'datetime',
            'opening_balance' => 'string',
            'closing_balance' => 'string',
            'cleared_balance' => 'string',
            'difference' => 'string',
        ];
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return HasMany<BankTransactionMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(BankTransactionMatch::class, 'reconciliation_id');
    }

    /**
     * @return HasMany<BankStatementLine, $this>
     */
    public function statementLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'reconciliation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * The account this reconciles, as a certainty. The column is not
     * nullable, so a null here means rows were deleted underneath us.
     */
    public function bankAccountOrFail(): BankAccount
    {
        $this->loadMissing('bankAccount');

        $bankAccount = $this->getRelation('bankAccount');

        if (! $bankAccount instanceof BankAccount) {
            throw new \LogicException(
                "Reconciliation {$this->number} has no bank account, which the schema forbids."
            );
        }

        return $bankAccount;
    }

    public function isCompleted(): bool
    {
        return $this->status === ReconciliationStatus::Completed;
    }

    public function reconciles(): bool
    {
        return BigDecimal::of($this->difference)->isZero();
    }
}
