<?php

declare(strict_types=1);

namespace App\Domain\Tax\Data;

/**
 * The whole document's figures.
 *
 * `subtotal` is gross of every discount and `discountTotal` holds them all,
 * because revenue is reported gross with discounts in contra-revenue. Netting
 * them here would destroy that split permanently — see ACCOUNTING_RULES.md
 * §4.1.
 */
final readonly class DocumentResult
{
    /**
     * @param  list<LineResult>  $lines
     * @param  list<TaxAmount>  $taxSummary  one entry per component, summed
     */
    public function __construct(
        public array $lines,
        public string $subtotal,
        public string $discountTotal,
        public string $taxableTotal,
        public string $taxTotal,
        public string $total,
        public array $taxSummary,
    ) {}
}
