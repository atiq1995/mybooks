<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Catalog\Models\Item;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Tax\Models\Tax;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line on a purchase document.
 *
 * The description, price, account and tax are COPIES taken when the line was
 * added, not references resolved on read — what keeps a two-year-old bill
 * readable after the item behind it changed, and its journal entry
 * permanently explicable.
 *
 * `debit_account_id` rather than `expense_account_id`: the cost of a purchase
 * lands in an expense account most of the time and in an asset account the
 * rest of the time — inventory, equipment, a prepayment — and calling the
 * column after the common case would make the others look like a misuse.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $purchase_document_id
 * @property int $line_no
 * @property string|null $item_id
 * @property string $description
 * @property string|null $unit
 * @property string $quantity
 * @property string $unit_price
 * @property string|null $discount_type
 * @property string|null $discount_value
 * @property string|null $tax_id
 * @property string $debit_account_id
 * @property bool $tax_is_claimable
 * @property string $gross
 * @property string $discount_amount
 * @property string $net
 * @property string $document_discount_amount
 * @property string $taxable
 * @property string $tax_total
 * @property string $total
 */
final class PurchaseDocumentLine extends Model
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
        'debit_account_id',
        'tax_is_claimable',
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
            'tax_is_claimable' => 'boolean',
            // numeric(19,6) on quantity, numeric(19,4) on the rest; every one
            // of them a decimal string.
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
     * What this line adds to the cost of whatever was bought.
     *
     * The taxable amount, plus the tax if that tax cannot be reclaimed. §4.6:
     * non-claimable input tax is capitalised into the expense or the
     * inventory value, because it is a real cost and not a receivable.
     */
    public function capitalisedCost(): string
    {
        $cost = BigDecimal::of($this->taxable);

        if (! $this->tax_is_claimable) {
            $cost = $cost->plus(BigDecimal::of($this->tax_total));
        }

        return (string) $cost->toScale(4);
    }

    /**
     * @return BelongsTo<PurchaseDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(PurchaseDocument::class, 'purchase_document_id');
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
    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'debit_account_id');
    }

    /**
     * @return HasMany<PurchaseDocumentLineTax, $this>
     */
    public function taxes(): HasMany
    {
        return $this->hasMany(PurchaseDocumentLineTax::class, 'purchase_document_line_id');
    }
}
