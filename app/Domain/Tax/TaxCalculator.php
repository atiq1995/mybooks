<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Tax\Data\DocumentResult;
use App\Domain\Tax\Data\LineInput;
use App\Domain\Tax\Data\LineResult;
use App\Domain\Tax\Data\TaxAmount;
use App\Domain\Tax\Data\TaxComponentRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Every figure on every sales and purchase document in this system.
 *
 * Pure: no database, no models, no tenant. Decimal strings in, decimal
 * strings out. That is what lets the whole of ACCOUNTING_RULES.md §5 be
 * asserted in microseconds — and it means the arithmetic can be reasoned
 * about without holding an invoice, a customer and a tax table in mind at
 * once.
 *
 * The ORDER is the specification, and it is not interchangeable:
 *
 *   1. gross    = quantity × unit price
 *   2. net      = gross − line discount
 *   3. apportion the document discount across lines, pro rata by net
 *   4. taxable  = net after all discounts
 *   5. per component, in sequence: simple on taxable, compound on
 *      taxable + preceding tax
 *   6. round each component
 *   7. total    = taxable + Σ tax
 *
 * Two decisions are worth stating outright, because both look like details
 * and neither is:
 *
 * ROUNDING happens at exactly two places — once per tax component, and once
 * per line amount. Everything in between is carried at full precision. Round
 * earlier and a hundred-line invoice drifts by cents; round later and the
 * document total stops equalling the sum of the lines the customer can see.
 *
 * APPORTIONMENT of a document discount is pro rata by net, with the last line
 * absorbing the remainder. Splitting 100 across three equal lines gives
 * 33.3333 each, which sums to 99.9999 — so the final line takes 33.3334. That
 * asymmetry is deliberate: the alternative is a document whose discount does
 * not equal what was agreed.
 *
 * @see ACCOUNTING_RULES.md §5
 */
final readonly class TaxCalculator
{
    /** numeric(19,4) — the scale money is stored at. */
    private const int MONEY_SCALE = 4;

    /**
     * Full precision for intermediate work. Twelve places is far more than
     * any real rate needs and costs nothing, since BigDecimal is exact.
     */
    private const int WORKING_SCALE = 12;

    /**
     * Calculate a whole document.
     *
     * @param  list<LineInput>  $lines
     * @param  'percentage'|'amount'|null  $documentDiscountType
     * @param  bool  $pricesIncludeTax  when true, the entered prices already
     *                                  contain tax and the net is extracted
     *                                  from them rather than tax being added
     */
    public function calculate(
        array $lines,
        ?string $documentDiscountType = null,
        ?string $documentDiscountValue = null,
        bool $pricesIncludeTax = false,
    ): DocumentResult {
        if ($lines === []) {
            throw new InvalidArgumentException('A document needs at least one line.');
        }

        // Steps 1 and 2, at full precision. Nothing is rounded yet.
        $working = array_map(
            fn (LineInput $line): array => $this->grossAndNet($line, $pricesIncludeTax),
            $lines,
        );

        // Step 3.
        $apportioned = $this->apportionDocumentDiscount(
            $working,
            $documentDiscountType,
            $documentDiscountValue,
        );

        $results = [];

        foreach ($lines as $index => $line) {
            $results[] = $this->finishLine(
                $line,
                $working[$index],
                $apportioned[$index],
                $pricesIncludeTax,
            );
        }

        return $this->summarise($results);
    }

    /**
     * Steps 1 and 2: gross, then the line's own discount.
     *
     * With tax-inclusive pricing the entered price contains the tax, so the
     * "gross" here is the tax-exclusive equivalent — extracted once, at full
     * precision, before any discount is applied. Discounting an inclusive
     * price and then extracting gives a different answer, and it is the wrong
     * one: the discount was agreed on the price the customer saw.
     *
     * @return array{gross: BigDecimal, discount: BigDecimal, net: BigDecimal}
     */
    private function grossAndNet(LineInput $line, bool $pricesIncludeTax): array
    {
        $quantity = BigDecimal::of($line->quantity);
        $unitPrice = BigDecimal::of($line->unitPrice);

        if ($quantity->isNegativeOrZero()) {
            throw new InvalidArgumentException(
                "A line quantity must be greater than zero; got {$line->quantity}."
            );
        }

        if ($unitPrice->isNegative()) {
            throw new InvalidArgumentException(
                "A unit price cannot be negative; got {$line->unitPrice}. ".
                'Use a credit note rather than a negative line.'
            );
        }

        $gross = $quantity->multipliedBy($unitPrice);

        if ($pricesIncludeTax) {
            $gross = $this->extractNetFrom($gross, $line->taxComponents);
        }

        $discount = $this->lineDiscount($line, $gross);

        return [
            'gross' => $gross,
            'discount' => $discount,
            'net' => $gross->minus($discount),
        ];
    }

    private function lineDiscount(LineInput $line, BigDecimal $gross): BigDecimal
    {
        if (! $line->hasDiscount()) {
            return BigDecimal::zero();
        }

        /** @var string $value */
        $value = $line->discountValue;
        $discount = BigDecimal::of($value);

        if ($discount->isNegative()) {
            throw new InvalidArgumentException("A discount cannot be negative; got {$value}.");
        }

        if ($line->discountType === 'percentage') {
            if ($discount->isGreaterThan(BigDecimal::of('100'))) {
                throw new InvalidArgumentException(
                    "A percentage discount cannot exceed 100; got {$value}."
                );
            }

            return $gross
                ->multipliedBy($discount)
                ->dividedBy(BigDecimal::of('100'), self::WORKING_SCALE, RoundingMode::HalfUp);
        }

        if ($discount->isGreaterThan($gross)) {
            throw new InvalidArgumentException(sprintf(
                'A discount of %s exceeds the line value of %s.',
                $value,
                (string) $gross->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
            ));
        }

        return $discount;
    }

    /**
     * Step 3: spread a document-level discount across the lines.
     *
     * Pro rata by net, so a line worth twice as much absorbs twice the
     * discount. The shares are rounded to money scale HERE, and the last line
     * takes whatever is left after the others — which is what makes them sum
     * to exactly the discount agreed.
     *
     * Rounding at this step rather than later is the whole trick. Splitting
     * 100 across three equal lines gives 33.333333… each; carried at full
     * precision the remainder lands in the twelfth decimal place and vanishes
     * when the line is finally rounded, leaving a document discounted by
     * 99.9999. Rounding first makes the shortfall visible — 33.3333 three
     * times is 99.9999 — so the final line can absorb it as 33.3334.
     *
     * @param  list<array{gross: BigDecimal, discount: BigDecimal, net: BigDecimal}>  $working
     * @return list<BigDecimal>
     */
    private function apportionDocumentDiscount(
        array $working,
        ?string $type,
        ?string $value,
    ): array {
        $zeroes = array_fill(0, count($working), BigDecimal::zero());

        if ($type === null || $value === null) {
            return $zeroes;
        }

        $netTotal = BigDecimal::zero();

        foreach ($working as $line) {
            $netTotal = $netTotal->plus($line['net']);
        }

        // Nothing to apportion across, and dividing by it would throw.
        if ($netTotal->isZero()) {
            return $zeroes;
        }

        $discount = BigDecimal::of($value);

        if ($discount->isNegative()) {
            throw new InvalidArgumentException("A document discount cannot be negative; got {$value}.");
        }

        if ($type === 'percentage') {
            if ($discount->isGreaterThan(BigDecimal::of('100'))) {
                throw new InvalidArgumentException(
                    "A percentage discount cannot exceed 100; got {$value}."
                );
            }

            $discount = $netTotal
                ->multipliedBy($discount)
                ->dividedBy(BigDecimal::of('100'), self::WORKING_SCALE, RoundingMode::HalfUp);
        } elseif ($discount->isGreaterThan($netTotal)) {
            throw new InvalidArgumentException(sprintf(
                'A document discount of %s exceeds the document net of %s.',
                $value,
                (string) $netTotal->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
            ));
        }

        // The discount itself, at the scale it will be reported at.
        $discount = $discount->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);

        $shares = [];
        $running = BigDecimal::zero();
        $last = count($working) - 1;

        foreach ($working as $index => $line) {
            if ($index === $last) {
                // Whatever is left, so the shares sum to the discount exactly.
                $shares[] = $discount->minus($running);

                break;
            }

            $share = $line['net']
                ->multipliedBy($discount)
                ->dividedBy($netTotal, self::MONEY_SCALE, RoundingMode::HalfUp);

            $shares[] = $share;
            $running = $running->plus($share);
        }

        return $shares;
    }

    /**
     * Steps 4 to 7 for one line.
     *
     * @param  array{gross: BigDecimal, discount: BigDecimal, net: BigDecimal}  $working
     */
    private function finishLine(
        LineInput $line,
        array $working,
        BigDecimal $documentDiscount,
        bool $pricesIncludeTax,
    ): LineResult {
        $taxable = $working['net']->minus($documentDiscount);

        /*
         * Apportionment rounding can leave a fractional negative where a
         * document discount consumed the whole line. Nothing is charged on a
         * negative base, and a negative tax would be refused downstream.
         */
        if ($taxable->isNegative()) {
            $taxable = BigDecimal::zero();
        }

        $taxes = $this->taxesFor($taxable, $line->taxComponents);

        $taxTotal = BigDecimal::zero();

        foreach ($taxes as $tax) {
            $taxTotal = $taxTotal->plus(BigDecimal::of($tax->taxAmount));
        }

        $roundedTaxable = $this->money($taxable);

        return new LineResult(
            reference: $line->reference,
            gross: $this->money($working['gross']),
            discountAmount: $this->money($working['discount']),
            net: $this->money($working['net']),
            documentDiscountAmount: $this->money($documentDiscount),
            taxable: $roundedTaxable,
            taxes: $taxes,
            taxTotal: $this->money($taxTotal),
            /*
             * Summed from the ROUNDED parts, not from full precision. The
             * customer can see the taxable amount and the tax on the
             * document, and the total has to be their sum — otherwise the
             * invoice appears not to add up, whatever the true arithmetic
             * says.
             */
            total: (string) BigDecimal::of($roundedTaxable)
                ->plus($taxTotal)
                ->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
        );
    }

    /**
     * Step 5 and 6: each component in sequence, rounded as it is produced.
     *
     * @param  list<TaxComponentRate>  $components
     * @return list<TaxAmount>
     */
    private function taxesFor(BigDecimal $taxable, array $components): array
    {
        if ($components === []) {
            return [];
        }

        // Sequence decides compounding, so it is applied rather than trusted.
        usort(
            $components,
            static fn (TaxComponentRate $a, TaxComponentRate $b): int => $a->sequence <=> $b->sequence,
        );

        $taxes = [];
        $precedingTax = BigDecimal::zero();

        foreach ($components as $component) {
            $base = $component->isCompound
                ? $taxable->plus($precedingTax)
                : $taxable;

            $amount = $this->money($base->multipliedBy($component->rateValue()));

            $taxes[] = new TaxAmount(
                componentId: $component->componentId,
                name: $component->name,
                rate: $component->rate,
                isCompound: $component->isCompound,
                taxableAmount: $this->money($base),
                taxAmount: $amount,
                accountId: $component->accountId,
            );

            /*
             * Compounding builds on the ROUNDED preceding tax, because that
             * is the figure on the document and the one the authority will
             * check against.
             */
            $precedingTax = $precedingTax->plus(BigDecimal::of($amount));
        }

        return $taxes;
    }

    /**
     * Tax-inclusive pricing: recover the net from a price that contains tax.
     *
     *     net = inclusive ÷ (1 + Σ rates)
     *
     * A compound component multiplies rather than adds, which is why the
     * divisor is built up in sequence rather than summed. Extraction happens
     * once, at full precision; the tax then falls out of the ordinary path
     * above, so the document total equals the inclusive prices entered. If
     * the user typed 1,180.00, the invoice says 1,180.00.
     *
     * @param  list<TaxComponentRate>  $components
     */
    private function extractNetFrom(BigDecimal $inclusive, array $components): BigDecimal
    {
        if ($components === []) {
            return $inclusive;
        }

        usort(
            $components,
            static fn (TaxComponentRate $a, TaxComponentRate $b): int => $a->sequence <=> $b->sequence,
        );

        $one = BigDecimal::one();
        $divisor = $one;

        foreach ($components as $component) {
            $divisor = $component->isCompound
                ? $divisor->multipliedBy($one->plus($component->rateValue()))
                : $divisor->plus($component->rateValue());
        }

        if ($divisor->isZero()) {
            return $inclusive;
        }

        return $inclusive->dividedBy($divisor, self::WORKING_SCALE, RoundingMode::HalfUp);
    }

    /**
     * @param  list<LineResult>  $results
     */
    private function summarise(array $results): DocumentResult
    {
        $subtotal = BigDecimal::zero();
        $discountTotal = BigDecimal::zero();
        $taxableTotal = BigDecimal::zero();
        $taxTotal = BigDecimal::zero();
        $total = BigDecimal::zero();

        /** @var array<string, TaxAmount> $byComponent */
        $byComponent = [];

        foreach ($results as $line) {
            $subtotal = $subtotal->plus(BigDecimal::of($line->gross));
            $discountTotal = $discountTotal
                ->plus(BigDecimal::of($line->discountAmount))
                ->plus(BigDecimal::of($line->documentDiscountAmount));
            $taxableTotal = $taxableTotal->plus(BigDecimal::of($line->taxable));
            $taxTotal = $taxTotal->plus(BigDecimal::of($line->taxTotal));
            $total = $total->plus(BigDecimal::of($line->total));

            foreach ($line->taxes as $tax) {
                $existing = $byComponent[$tax->componentId] ?? null;

                $byComponent[$tax->componentId] = new TaxAmount(
                    componentId: $tax->componentId,
                    name: $tax->name,
                    rate: $tax->rate,
                    isCompound: $tax->isCompound,
                    taxableAmount: $existing === null
                        ? $tax->taxableAmount
                        : $this->money(
                            BigDecimal::of($existing->taxableAmount)
                                ->plus(BigDecimal::of($tax->taxableAmount)),
                        ),
                    taxAmount: $existing === null
                        ? $tax->taxAmount
                        : $this->money(
                            BigDecimal::of($existing->taxAmount)
                                ->plus(BigDecimal::of($tax->taxAmount)),
                        ),
                    accountId: $tax->accountId,
                );
            }
        }

        return new DocumentResult(
            lines: $results,
            subtotal: $this->money($subtotal),
            discountTotal: $this->money($discountTotal),
            taxableTotal: $this->money($taxableTotal),
            taxTotal: $this->money($taxTotal),
            total: $this->money($total),
            taxSummary: array_values($byComponent),
        );
    }

    private function money(BigDecimal $value): string
    {
        return (string) $value->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);
    }
}
