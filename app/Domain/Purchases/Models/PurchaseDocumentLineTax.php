<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Tax\Models\TaxComponent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tax component's amount on one purchase line.
 *
 * A row rather than a JSON field, because "input tax paid this quarter, by
 * component, that we may actually claim" IS the input half of the tax return
 * — and that query has to be an indexed aggregate.
 *
 * `is_claimable` is copied down from the line for exactly that reason: the
 * return can then be filed straight from these rows, without joining back to
 * establish which of them count.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $purchase_document_id
 * @property string $purchase_document_line_id
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
final class PurchaseDocumentLineTax extends Model
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
     * @return BelongsTo<PurchaseDocumentLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(PurchaseDocumentLine::class, 'purchase_document_line_id');
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
