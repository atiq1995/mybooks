<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Tax\Models\TaxComponent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tax component's amount on one expense line.
 *
 * A row rather than a JSON field, for the same reason as on the other two
 * modules: this query is part of the input half of the tax return, and it has
 * to be an indexed aggregate. `is_claimable` is copied down from the line so
 * the return can be filed from these rows alone.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $expense_id
 * @property string $expense_line_id
 * @property string $tax_component_id
 * @property string $component_name
 * @property string $rate
 * @property bool $is_compound
 * @property bool $is_claimable
 * @property string $taxable_amount
 * @property string $tax_amount
 * @property string $tax_amount_base
 * @property string|null $account_id
 */
final class ExpenseLineTax extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'tax_component_id',
        'component_name',
        'rate',
        'is_compound',
        'is_claimable',
        'taxable_amount',
        'tax_amount',
        'tax_amount_base',
        'account_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'string',
            'is_compound' => 'boolean',
            'is_claimable' => 'boolean',
            'taxable_amount' => 'string',
            'tax_amount' => 'string',
            'tax_amount_base' => 'string',
        ];
    }

    /**
     * @return BelongsTo<ExpenseLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(ExpenseLine::class, 'expense_line_id');
    }

    /**
     * @return BelongsTo<TaxComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(TaxComponent::class, 'tax_component_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
