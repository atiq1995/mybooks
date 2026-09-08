<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Tax\Models\Tax;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line on an expense — a sum spent, or a distance travelled.
 *
 * Mileage reuses `quantity` and `unit_price` rather than getting columns of
 * its own: a distance at a rate per unit is the same multiplication as a
 * quantity at a price, so the tax engine needs no special case and the totals
 * fall out unchanged. `kind` says which the reader is looking at, and `unit`
 * says what the distance is measured in.
 *
 * The rate is COPIED onto the line. `mileage_rate_id` records which rate it
 * came from, for the audit trail, but the figure that was claimed is the one
 * stored here — a rate that changes in April must not restate March.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $expense_id
 * @property int $line_no
 * @property string $kind
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string|null $unit
 * @property string|null $mileage_rate_id
 * @property string|null $tax_id
 * @property string $debit_account_id
 * @property bool $tax_is_claimable
 * @property string $gross
 * @property string $net
 * @property string $taxable
 * @property string $tax_total
 * @property string $total
 */
final class ExpenseLine extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'kind',
        'description',
        'quantity',
        'unit_price',
        'unit',
        'mileage_rate_id',
        'tax_id',
        'debit_account_id',
        'tax_is_claimable',
        'project_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'tax_is_claimable' => 'boolean',
            'quantity' => 'string',
            'unit_price' => 'string',
            'gross' => 'string',
            'net' => 'string',
            'taxable' => 'string',
            'tax_total' => 'string',
            'total' => 'string',
        ];
    }

    /**
     * What this line adds to the cost of whatever was bought.
     *
     * The taxable amount, plus the tax if that tax cannot be reclaimed. §4.6
     * again, and it bites hardest here: blocked input tax turns up on
     * expenses more than anywhere else.
     */
    public function capitalisedCost(): string
    {
        $cost = BigDecimal::of($this->taxable);

        if (! $this->tax_is_claimable) {
            $cost = $cost->plus(BigDecimal::of($this->tax_total));
        }

        return (string) $cost->toScale(4);
    }

    public function isMileage(): bool
    {
        return $this->kind === 'mileage';
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * @return BelongsTo<Tax, $this>
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'debit_account_id');
    }

    /**
     * @return BelongsTo<MileageRate, $this>
     */
    public function mileageRate(): BelongsTo
    {
        return $this->belongsTo(MileageRate::class, 'mileage_rate_id');
    }

    /**
     * @return HasMany<ExpenseLineTax, $this>
     */
    public function taxes(): HasMany
    {
        return $this->hasMany(ExpenseLineTax::class, 'expense_line_id');
    }
}
