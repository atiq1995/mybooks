<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Organizations\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One currency pair's rate on one day.
 *
 * Ten decimal places: money needs four, but a rate multiplied by a large
 * amount needs more precision than the result does.
 *
 * One rate per pair per day, enforced by a unique index — a second would make
 * conversion non-deterministic, and two people would get different answers
 * from the same invoice.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $from_currency
 * @property string $to_currency
 * @property string $rate
 * @property Carbon $effective_on
 * @property string $source
 */
final class ExchangeRate extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = ['from_currency', 'to_currency', 'rate', 'effective_on', 'source'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            // NOT a float cast. The value stays the decimal string PostgreSQL
            // returned, and arithmetic on it happens in BigDecimal.
            'rate' => 'string',
        ];
    }

    /**
     * How this rate got here: 'manual', 'imported' or 'api'.
     *
     * Provenance matters when a figure is questioned months later — "who said
     * the rate was 278.50 that day" is a real audit question.
     */
    public function isManual(): bool
    {
        return $this->source === 'manual';
    }
}
