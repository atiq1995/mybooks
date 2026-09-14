<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Catalog\Models\Item;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Tax\Models\Tax;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a recurring-invoice template.
 *
 * No computed columns, deliberately. A template has no totals, because the
 * tax rates in force when it generates are not necessarily the ones in force
 * today — every figure is computed on the invoice it produces, at that
 * invoice's date. Storing a total here would be a number that looks
 * authoritative and is out of date the first time a rate changes.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $recurring_invoice_id
 * @property int $line_no
 * @property string|null $item_id
 * @property string $description
 * @property string|null $unit
 * @property string $quantity
 * @property string $unit_price
 * @property string|null $discount_type
 * @property string|null $discount_value
 * @property string|null $tax_id
 * @property string $revenue_account_id
 */
final class RecurringInvoiceLine extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'item_id',
        'description',
        'unit',
        'quantity',
        'unit_price',
        'discount_type',
        'discount_value',
        'tax_id',
        'revenue_account_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'quantity' => 'string',
            'unit_price' => 'string',
            'discount_value' => 'string',
        ];
    }

    /**
     * @return BelongsTo<RecurringInvoice, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class, 'recurring_invoice_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
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
    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }
}
