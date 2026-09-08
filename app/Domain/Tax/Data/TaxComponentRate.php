<?php

declare(strict_types=1);

namespace App\Domain\Tax\Data;

use App\Domain\Tax\TaxCalculator;
use Brick\Math\BigDecimal;

/**
 * One tax component, as the calculator sees it.
 *
 * A plain value: no model, no database. The calculator is a pure function of
 * lines and rates, which is what lets every case in ACCOUNTING_RULES.md §5 be
 * asserted in microseconds and without a tenant.
 *
 * @see TaxCalculator
 */
final readonly class TaxComponentRate
{
    public function __construct(
        public string $componentId,
        public string $name,
        /** A fraction, not a percentage: 18% is '0.18'. */
        public string $rate,
        public int $sequence,
        /**
         * Whether this component taxes the preceding components as well as
         * the net. Rare — but where it applies, ignoring it understates the
         * liability, and nobody notices until the return is filed.
         */
        public bool $isCompound = false,
        public ?string $accountId = null,
    ) {}

    public function rateValue(): BigDecimal
    {
        return BigDecimal::of($this->rate);
    }
}
