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
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line on a sales document.
 *
 * The description, price, account and tax are COPIES taken when the line was
 * added, not references resolved on read. That is what keeps a historical
 * invoice readable after the item behind it changed — and what makes the
 * journal entry it produced permanently explicable.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $sales_document_id
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
 * @property string $gross
 * @property string $discount_amount
 * @property string $net
 * @property string $document_discount_amount
 * @property string $taxable
 * @property string $tax_total
 * @property string $total
 */
final class SalesDocumentLine extends Model
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
        'project_id',
        'warehouse_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            // Every amount stays a decimal string. numeric(19,6) on quantity,
            // numeric(19,4) on the rest.
            'quantity' => 'string',
            'unit_price' => 'string',
            'discount_value' => 'string',
            'gross' => 'string',
            'discount_amount' => 'string',
            'net' => 'string',
            'document_discount_amount' => 'string',
            'taxable' => 'string',
            'tax_total' => 'string',
            'total' => 'string',
        ];
    }

    /**
     * @return BelongsTo<SalesDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
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

    /**
     * @return HasMany<SalesDocumentLineTax, $this>
     */
    public function taxes(): HasMany
    {
        return $this->hasMany(SalesDocumentLineTax::class, 'sales_document_line_id');
    }
}
