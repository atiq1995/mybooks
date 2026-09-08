<?php

declare(strict_types=1);

namespace App\Domain\Tax\Data;

use App\Domain\Tax\TaxCalculator;

/**
 * What the calculator worked out for one line.
 *
 * Every figure is a decimal string at money scale, already rounded. The
 * caller stores these verbatim; it does not recompute them, and it does not
 * round them again.
 *
 * @see TaxCalculator
 */
final readonly class LineResult
{
    /**
     * @param  list<TaxAmount>  $taxes
     */
    public function __construct(
        public ?string $reference,
        /** quantity × unit price, before any discount. */
        public string $gross,
        /** The line's own discount. */
        public string $discountAmount,
        /** Gross less the line discount. */
        public string $net,
        /** This line's share of the document-level discount. */
        public string $documentDiscountAmount,
        /** What tax is actually charged on: net less both discounts. */
        public string $taxable,
        public array $taxes,
        public string $taxTotal,
        /** taxable + taxTotal. */
        public string $total,
    ) {}
}
