<?php

declare(strict_types=1);

namespace App\Domain\Banking\Models;

use App\Domain\Banking\Enums\StatementLineStatus;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Organizations\Models\Organization;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One line of a bank statement.
 *
 * The bank's record, not ours. It is evidence about our books, and it becomes
 * connected to them only through a match somebody confirmed.
 *
 * `amount` is signed: positive money in, negative money out. The sign is the
 * whole of the direction information, which keeps the matcher's arithmetic
 * honest — a line for -12,500 can only ever clear a credit of 12,500 on the
 * bank account, never a debit.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $bank_account_id
 * @property string $import_id
 * @property Carbon $transaction_date
 * @property string $description
 * @property string|null $reference
 * @property string|null $payee
 * @property string $amount
 * @property string|null $statement_balance
 * @property string $fingerprint
 * @property StatementLineStatus $status
 * @property string|null $excluded_reason
 * @property string|null $reconciliation_id
 */
final class BankStatementLine extends Model
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
            'transaction_date' => 'date',
            'status' => StatementLineStatus::class,
            // Decimal strings, like every other money column. A cast to float
            // here would undo the numeric(19,4) storage in one step.
            'amount' => 'string',
            'statement_balance' => 'string',
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
     * @return BelongsTo<BankStatementImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'import_id');
    }

    /**
     * @return HasMany<BankTransactionMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(BankTransactionMatch::class, 'statement_line_id');
    }

    /**
     * @return BelongsTo<BankReconciliation, $this>
     */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'reconciliation_id');
    }

    /**
     * The account this line is on, as a certainty.
     *
     * A statement line without a bank account is not a state the schema
     * allows — the column is not nullable — so this converts "the relation
     * might be null" into the one thing it can actually mean: somebody
     * deleted rows underneath us.
     */
    public function bankAccountOrFail(): BankAccount
    {
        // Loaded explicitly: lazy loading is off application-wide, and a
        // silent N+1 in the matcher would be a query per statement line.
        $this->loadMissing('bankAccount');

        $bankAccount = $this->getRelation('bankAccount');

        if (! $bankAccount instanceof BankAccount) {
            throw new \LogicException(
                "Statement line {$this->id} has no bank account, which the schema forbids."
            );
        }

        return $bankAccount;
    }

    /** Money into the account. */
    public function isInflow(): bool
    {
        return BigDecimal::of($this->amount)->isPositive();
    }

    public function isLocked(): bool
    {
        return $this->reconciliation_id !== null;
    }

    /**
     * What has been matched against this line so far, signed.
     *
     * Used to decide whether a part-matched line — a single deposit covering
     * two invoices, say — is finished.
     */
    public function matchedAmount(): BigDecimal
    {
        return $this->matches
            ->reduce(
                static fn (BigDecimal $carry, BankTransactionMatch $match): BigDecimal => $carry->plus($match->amount),
                BigDecimal::zero(),
            );
    }

    /**
     * The description a person would recognise.
     *
     * Statement descriptions are frequently a payee glued to a reference and
     * a terminal id. Where the format gave us the payee separately, that is
     * the more useful line to show.
     */
    public function summary(): string
    {
        return $this->payee ?? $this->description;
    }

    /**
     * The fingerprint that makes a re-import a no-op.
     *
     * The content of the line, plus which occurrence of that content this is
     * within the account. The occurrence index is what keeps two genuinely
     * identical payments on the same day — a pair of matching transfers, two
     * identical fuel receipts — from collapsing into one, while a file
     * imported twice still collides on every row.
     */
    public static function fingerprintFor(
        Organization|string $organization,
        string $bankAccountId,
        string $date,
        string $amount,
        string $description,
        ?string $reference,
        int $occurrence,
    ): string {
        $organizationId = $organization instanceof Organization
            ? $organization->id
            : $organization;

        return hash('sha256', implode('|', [
            $organizationId,
            $bankAccountId,
            $date,
            (string) BigDecimal::of($amount)->toScale(4),
            mb_strtolower(trim(preg_replace('/\s+/', ' ', $description) ?? $description)),
            mb_strtolower(trim($reference ?? '')),
            (string) $occurrence,
        ]));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', StatementLineStatus::Unmatched);
    }
}
