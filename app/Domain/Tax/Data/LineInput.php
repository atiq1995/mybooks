<?php

declare(strict_types=1);

namespace App\Domain\Tax\Data;

use App\Domain\Tax\TaxCalculator;

/**
 * One document line, as the calculator receives it.
 *
 * Everything is a decimal string. A quantity of 0.1 hours and a price of
 * 0.1 are both unrepresentable as doubles, and a line is where that error
 * would enter and then be multiplied.
 *
 * @see TaxCalculator
 */
final readonly class LineInput
{
    /**
     * @param  list<TaxComponentRate>  $taxComponents  in `sequence` order
     * @param  'percentage'|'amount'|null  $discountType
     */
    public function __construct(
        public string $quantity,
        public string $unitPrice,
        public array $taxComponents = [],
        public ?string $discountType = null,
        public ?string $discountValue = null,
        /** Carried through untouched, so a caller can match results to rows. */
        public ?string $reference = null,
    ) {}

    public function hasDiscount(): bool
    {
        return $this->discountType !== null && $this->discountValue !== null;
    }
}
