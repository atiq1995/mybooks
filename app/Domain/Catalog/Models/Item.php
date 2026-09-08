<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Catalog\Enums\ItemKind;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Tax\Models\Tax;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something you sell or buy.
 *
 * An item supplies DEFAULTS. A document line copies its description, price,
 * account and tax at the moment the line is added, and the user can change
 * any of them — which is what keeps a two-year-old invoice readable after the
 * item has been renamed, repriced or archived.
 *
 * @property string $id
 * @property string $organization_id
 * @property ItemKind $kind
 * @property string|null $sku
 * @property string $name
 * @property string|null $description
 * @property string|null $unit
 * @property string|null $sale_price
 * @property string|null $purchase_price
 * @property string|null $currency
 * @property string|null $sales_account_id
 * @property string|null $purchase_account_id
 * @property string|null $inventory_account_id
 * @property string|null $sales_tax_id
 * @property string|null $purchase_tax_id
 * @property bool $is_tracked
 * @property bool $is_sold
 * @property bool $is_purchased
 * @property bool $is_active
 * @property Carbon|null $archived_at
 */
final class Item extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'kind',
        'sku',
        'name',
        'description',
        'unit',
        'sale_price',
        'purchase_price',
        'currency',
        'sales_account_id',
        'purchase_account_id',
        'inventory_account_id',
        'sales_tax_id',
        'purchase_tax_id',
        'is_tracked',
        'is_sold',
        'is_purchased',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ItemKind::class,
            // Decimal strings, never floats — this is a money path.
            'sale_price' => 'string',
            'purchase_price' => 'string',
            'is_tracked' => 'boolean',
            'is_sold' => 'boolean',
            'is_purchased' => 'boolean',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function salesAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'sales_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function purchaseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'purchase_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_account_id');
    }

    /**
     * @return BelongsTo<Tax, $this>
     */
    public function salesTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'sales_tax_id');
    }

    /**
     * @return BelongsTo<Tax, $this>
     */
    public function purchaseTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'purchase_tax_id');
    }

    public function acceptsUse(): bool
    {
        return $this->is_active && $this->archived_at === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->usable()->where('is_sold', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->usable()->where('is_purchased', true);
    }
}
