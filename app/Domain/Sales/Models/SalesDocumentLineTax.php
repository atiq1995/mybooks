<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Tax\Models\TaxComponent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tax component's amount on one line.
 *
 * A row rather than a JSON field, because "GST collected this quarter, by
 * component" IS the sales tax return — and that query has to be an indexed
 * aggregate, not a scan with a JSON traversal.
 *
 * The name and rate are copied so the return stays readable after a rename or
 * a rate change.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $sales_document_id
 * @property string $sales_document_line_id
 * @property string $tax_component_id
 * @property string $component_name
 * @property string $rate
 * @property bool $is_compound
 * @property string $taxable_amount
 * @property string $tax_amount
 * @property string $tax_amount_base
 * @property string|null $account_id
 */
final class SalesDocumentLineTax extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'tax_component_id',
        'component_name',
        'rate',
        'is_compound',
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
            'taxable_amount' => 'string',
            'tax_amount' => 'string',
            'tax_amount_base' => 'string',
        ];
    }

    /**
     * @return BelongsTo<SalesDocumentLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(SalesDocumentLine::class, 'sales_document_line_id');
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
