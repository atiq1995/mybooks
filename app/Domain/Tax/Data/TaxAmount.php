<?php

declare(strict_types=1);

namespace App\Domain\Tax\Data;

/**
 * One component's tax on one line.
 *
 * Kept per component rather than summed, because the sales tax return is
 * filed per component and a total cannot be taken apart again.
 */
final readonly class TaxAmount
{
    public function __construct(
        public string $componentId,
        public string $name,
        public string $rate,
        public bool $isCompound,
        /** What this component was charged on — for a compound component,
         *  more than the line's taxable amount. */
        public string $taxableAmount,
        public string $taxAmount,
        public ?string $accountId = null,
    ) {}
}
