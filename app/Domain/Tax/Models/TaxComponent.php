<?php

declare(strict_types=1);

namespace App\Domain\Tax\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Sales\Models\SalesDocumentLineTax;
use App\Domain\Tax\Data\TaxComponentRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One rate within a tax, valid over a date range.
 *
 * The rate is stored as a FRACTION — 18% is 0.180000 — so nothing downstream
 * has to remember to divide by a hundred. Six decimal places, because a rate
 * is a proportion rather than money and 0.166667 occurs.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $tax_id
 * @property string $name
 * @property string $rate
 * @property int $sequence
 * @property bool $is_compound
 * @property string|null $output_account_id
 * @property string|null $input_account_id
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
final class TaxComponent extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'name',
        'rate',
        'sequence',
        'is_compound',
        'output_account_id',
        'input_account_id',
        'effective_from',
        'effective_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // NOT a float cast. The value stays the decimal string PostgreSQL
            // returned, and arithmetic on it happens in BigDecimal.
            'rate' => 'string',
            'sequence' => 'integer',
            'is_compound' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
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
    public function outputAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'output_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function inputAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'input_account_id');
    }

    /**
     * The document lines this component has priced.
     *
     * Its existence is what stops a component version being deleted: the tax
     * return for a filed period reads through these rows, and removing the
     * component would make that period unreproducible.
     *
     * @return HasMany<SalesDocumentLineTax, $this>
     */
    public function lineTaxes(): HasMany
    {
        return $this->hasMany(SalesDocumentLineTax::class, 'tax_component_id');
    }

    /**
     * The rate as a BigDecimal.
     */
    public function rateValue(): BigDecimal
    {
        return BigDecimal::of($this->rate);
    }

    /**
     * Hand this component to the calculator.
     *
     * The account travels with it, so the posting rule does not have to look
     * the component up a second time to know where its tax lands.
     *
     * @param  'sales'|'purchase'  $direction
     */
    public function toRate(string $direction = 'sales'): TaxComponentRate
    {
        return new TaxComponentRate(
            componentId: $this->id,
            name: $this->name,
            rate: $this->rate,
            sequence: $this->sequence,
            isCompound: $this->is_compound,
            accountId: $direction === 'sales'
                ? $this->output_account_id
                : $this->input_account_id,
        );
    }

    public function percentage(): string
    {
        $percentage = (string) BigDecimal::of($this->rate)
            ->multipliedBy(100)
            ->toScale(4, RoundingMode::HalfUp);

        // 18.0000 reads as 18; 13.5000 reads as 13.5.
        return rtrim(rtrim($percentage, '0'), '.');
    }
}
