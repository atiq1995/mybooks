<?php

declare(strict_types=1);

use App\Domain\Tax\Data\LineInput;
use App\Domain\Tax\Data\TaxComponentRate;
use App\Domain\Tax\TaxCalculator;

/*
|---------------------------------------------------------------------------
| The tax engine
|---------------------------------------------------------------------------
|
| Every figure on every sales and purchase document comes from here, so this
| is the most consequential pure function in the system. Pure is the point:
| no database, no tenant, so the whole of ACCOUNTING_RULES.md §5 is asserted
| in microseconds.
|
| The ORDER of operations is the specification. Several tests below exist only
| to prove that reordering two steps changes the answer — which is why the
| order is written down rather than left to whoever edits next.
|
| @see ACCOUNTING_RULES.md §5
*/

beforeEach(function (): void {
    $this->calculator = new TaxCalculator;

    /** Pakistan's standard sales tax on goods. */
    $this->gst = new TaxComponentRate(
        componentId: 'gst',
        name: 'GST 18%',
        rate: '0.180000',
        sequence: 1,
        accountId: 'gst-output',
    );

    /** A provincial services tax, for the multi-component cases. */
    $this->pst = new TaxComponentRate(
        componentId: 'pst',
        name: 'PST 13%',
        rate: '0.130000',
        sequence: 2,
        accountId: 'pst-output',
    );

    /** Applied to unregistered buyers, on top of the sales tax. */
    $this->further = new TaxComponentRate(
        componentId: 'further',
        name: 'Further tax 3%',
        rate: '0.030000',
        sequence: 2,
        isCompound: true,
        accountId: 'further-output',
    );
});

describe('the worked example from the rules', function (): void {
    it('produces §4.1 exactly: 100,000 net, 5% discount, 18% GST', function (): void {
        $result = $this->calculator->calculate([
            new LineInput(
                quantity: '1',
                unitPrice: '100000.00',
                taxComponents: [$this->gst],
                discountType: 'percentage',
                discountValue: '5',
            ),
        ]);

        // The figures the posting rule table names, to the cent.
        expect($result->subtotal)->toBeDecimal('100000.0000')
            ->and($result->discountTotal)->toBeDecimal('5000.0000')
            ->and($result->taxableTotal)->toBeDecimal('95000.0000')
            ->and($result->taxTotal)->toBeDecimal('17100.0000')
            ->and($result->total)->toBeDecimal('112100.0000');
    });
});

describe('quantity and price', function (): void {
    it('multiplies at full precision before rounding once', function (): void {
        // 3.5 hours at 1,234.5678 is 4,320.9873, not 4,320.99 rounded per unit.
        $result = $this->calculator->calculate([
            new LineInput(quantity: '3.5', unitPrice: '1234.5678'),
        ]);

        expect($result->subtotal)->toBeDecimal('4320.9873')
            ->and($result->total)->toBeDecimal('4320.9873');
    });

    it('handles a fractional quantity at six places', function (): void {
        // A sixth of a unit: the kind of quantity that only exists because
        // quantity is numeric(19,6) rather than an integer.
        $result = $this->calculator->calculate([
            new LineInput(quantity: '0.166667', unitPrice: '900.00'),
        ]);

        expect($result->subtotal)->toBeDecimal('150.0003');
    });

    it('accepts a price of zero, for a free line on a real invoice', function (): void {
        $result = $this->calculator->calculate([
            new LineInput(quantity: '1', unitPrice: '0', taxComponents: [$this->gst]),
        ]);

        expect($result->total)->toBeDecimal('0.0000')
            ->and($result->taxTotal)->toBeDecimal('0.0000');
    });

    it('refuses a zero or negative quantity', function (): void {
        expect(fn () => $this->calculator->calculate([
            new LineInput(quantity: '0', unitPrice: '100'),
        ]))->toThrow(InvalidArgumentException::class, 'greater than zero');

        expect(fn () => $this->calculator->calculate([
            new LineInput(quantity: '-1', unitPrice: '100'),
        ]))->toThrow(InvalidArgumentException::class, 'greater than zero');
    });

    it('refuses a negative price, and says to use a credit note', function (): void {
        // A negative line would post a credit to revenue while calling itself
        // a sale, which makes gross sales unreportable.
        expect(fn () => $this->calculator->calculate([
            new LineInput(quantity: '1', unitPrice: '-100'),
        ]))->toThrow(InvalidArgumentException::class, 'credit note');
    });

    it('refuses a document with no lines', function (): void {
        expect(fn () => $this->calculator->calculate([]))
            ->toThrow(InvalidArgumentException::class, 'at least one line');
    });
});

describe('line discounts', function (): void {
    it('takes a percentage off the gross', function (): void {
        $result = $this->calculator->calculate([
            new LineInput(
                quantity: '10',
                unitPrice: '500.00',
                discountType: 'percentage',
                discountValue: '12.5',
            ),
        ]);

        expect($result->subtotal)->toBeDecimal('5000.0000')
            ->and($result->discountTotal)->toBeDecimal('625.0000')
            ->and($result->taxableTotal)->toBeDecimal('4375.0000');
    });

    it('takes a fixed amount off the gross', function (): void {
        $result = $this->calculator->calculate([
            new LineInput(
                quantity: '1',
                unitPrice: '5000.00',
                discountType: 'amount',
                discountValue: '750.50',
            ),
        ]);

        expect($result->discountTotal)->toBeDecimal('750.5000')
            ->and($result->taxableTotal)->toBeDecimal('4249.5000');
    });

    it('taxes the discounted amount, not the gross', function (): void {
        /*
         * The order in §5 is not interchangeable. Taxing before the discount
         * would give 18,000 here rather than 17,100 — a 900 overstatement of
         * the liability on a single line.
         */
        $result = $this->calculator->calculate([
            new LineInput(
                quantity: '1',
                unitPrice: '100000.00',
                taxComponents: [$this->gst],
                discountType: 'percentage',
                discountValue: '5',
            ),
        ]);

        expect($result->taxTotal)->toBeDecimal('17100.0000')
            ->and($result->taxTotal)->not->toBe('18000.0000');
    });

    it('refuses a discount larger than the line', function (): void {
        expect(fn () => $this->calculator->calculate([
            new LineInput(
                quantity: '1',
                unitPrice: '100',
                discountType: 'amount',
                discountValue: '150',
            ),
        ]))->toThrow(InvalidArgumentException::class, 'exceeds the line value');
    });

    it('refuses a percentage above 100 and a negative discount', function (): void {
        expect(fn () => $this->calculator->calculate([
            new LineInput(quantity: '1', unitPrice: '100', discountType: 'percentage', discountValue: '101'),
        ]))->toThrow(InvalidArgumentException::class, 'cannot exceed 100');

        expect(fn () => $this->calculator->calculate([
            new LineInput(quantity: '1', unitPrice: '100', discountType: 'amount', discountValue: '-5'),
        ]))->toThrow(InvalidArgumentException::class, 'cannot be negative');
    });

    it('allows a discount of the whole line', function (): void {
        $result = $this->calculator->calculate([
            new LineInput(
                quantity: '1',
                unitPrice: '100',
                taxComponents: [$this->gst],
                discountType: 'percentage',
                discountValue: '100',
            ),
        ]);

        // Fully discounted: nothing taxable, so no tax — not a zero-rated
        // supply, simply nothing to charge on.
        expect($result->taxableTotal)->toBeDecimal('0.0000')
            ->and($result->taxTotal)->toBeDecimal('0.0000')
            ->and($result->total)->toBeDecimal('0.0000');
    });
});

describe('document discounts', function (): void {
    it('apportions pro rata by net, so a bigger line absorbs more', function (): void {
        $result = $this->calculator->calculate(
            lines: [
                new LineInput(quantity: '1', unitPrice: '3000.00', reference: 'a'),
                new LineInput(quantity: '1', unitPrice: '1000.00', reference: 'b'),
            ],
            documentDiscountType: 'amount',
            documentDiscountValue: '400.00',
        );

        // 3:1 by net, so 300 and 100.
        expect($result->lines[0]->documentDiscountAmount)->toBeDecimal('300.0000')
            ->and($result->lines[1]->documentDiscountAmount)->toBeDecimal('100.0000')
            ->and($result->discountTotal)->toBeDecimal('400.0000')
            ->and($result->taxableTotal)->toBeDecimal('3600.0000');
    });

    it('gives the remainder to the last line, so the discount is exact', function (): void {
        /*
         * 100 across three equal lines is 33.3333 each, which sums to
         * 99.9999. The last line takes 33.3334 so the document's discount is
         * the 100 that was agreed — a cent short would be a document that
         * does not match the conversation.
         */
        $result = $this->calculator->calculate(
            lines: [
                new LineInput(quantity: '1', unitPrice: '1000.00'),
                new LineInput(quantity: '1', unitPrice: '1000.00'),
                new LineInput(quantity: '1', unitPrice: '1000.00'),
            ],
            documentDiscountType: 'amount',
            documentDiscountValue: '100.00',
        );

        expect($result->lines[0]->documentDiscountAmount)->toBeDecimal('33.3333')
            ->and($result->lines[1]->documentDiscountAmount)->toBeDecimal('33.3333')
            ->and($result->lines[2]->documentDiscountAmount)->toBeDecimal('33.3334')
            ->and($result->discountTotal)->toBeDecimal('100.0000');
    });

    it('applies a document percentage to the post-line-discount net', function (): void {
        $result = $this->calculator->calculate(
            lines: [
                new LineInput(
                    quantity: '1',
                    unitPrice: '1000.00',
                    discountType: 'percentage',
                    discountValue: '10',
                ),
            ],
            documentDiscountType: 'percentage',
            documentDiscountValue: '10',
        );

        // 10% of 900, not 10% of 1,000: §5 step 3 apportions across NET.
        expect($result->lines[0]->documentDiscountAmount)->toBeDecimal('90.0000')
            ->and($result->taxableTotal)->toBeDecimal('810.0000')
            ->and($result->discountTotal)->toBeDecimal('190.0000');
    });

    it('stacks with line discounts and taxes what is left', function (): void {
        $result = $this->calculator->calculate(
            lines: [
                new LineInput(
                    quantity: '2',
                    unitPrice: '1000.00',
                    taxComponents: [$this->gst],
                    discountType: 'amount',
                    discountValue: '200.00',
                ),
            ],
            documentDiscountType: 'amount',
            documentDiscountValue: '300.00',
        );

        // 2,000 gross − 200 line − 300 document = 1,500 taxable, 270 GST.
        expect($result->taxableTotal)->toBeDecimal('1500.0000')
            ->and($result->taxTotal)->toBeDecimal('270.0000')
            ->and($result->total)->toBeDecimal('1770.0000');
    });

    it('refuses a document discount larger than the document', function (): void {
        expect(fn () => $this->calculator->calculate(
            lines: [new LineInput(quantity: '1', unitPrice: '100')],
            documentDiscountType: 'amount',
            documentDiscountValue: '200',
        ))->toThrow(InvalidArgumentException::class, 'exceeds the document net');
    });

    it('does nothing when every line is already fully discounted', function (): void {
        // Net is zero, so there is nothing to apportion across — and dividing
        // by it would throw rather than produce a document.
        $result = $this->calculator->calculate(
            lines: [
                new LineInput(
                    quantity: '1',
                    unitPrice: '100',
                    discountType: 'percentage',
                    discountValue: '100',
                ),
            ],
            documentDiscountType: 'percentage',
            documentDiscountValue: '10',
        );

        expect($result->total)->toBeDecimal('0.0000');
    });
});

describe('tax components', function (): void {
    it('charges several simple components on the same taxable amount', function (): void {
        $result = $this->calculator->calculate([
            new LineInput(
                quantity: '1',
                unitPrice: '1000.00',
                taxComponents: [$this->gst, $this->pst],
            ),
        ]);

        expect($result->taxTotal)->toBeDecimal('310.0000');

        // Kept apart, because the return is filed per component and a total
        // cannot be taken apart again.
        expect($result->taxSummary)->toHaveCount(2);
        expect($result->taxSummary[0]->taxAmount)->toBeDecimal('180.0000');
        expect($result->taxSummary[1]->taxAmount)->toBeDecimal('130.0000');
    });

    it('compounds a component onto the tax before it', function (): void {
        $result = $this->calculator->calculate([
            new LineInput(
                quantity: '1',
                unitPrice: '1000.00',
                taxComponents: [$this->gst, $this->further],
            ),
        ]);

        // 18% of 1,000 = 180; then 3% of 1,180 = 35.40, not 3% of 1,000.
        expect($result->lines[0]->taxes[0]->taxAmount)->toBeDecimal('180.0000');
        expect($result->lines[0]->taxes[1]->taxableAmount)->toBeDecimal('1180.0000');
        expect($result->lines[0]->taxes[1]->taxAmount)->toBeDecimal('35.4000');
        expect($result->taxTotal)->toBeDecimal('215.4000');
    });

    it('applies components in sequence order, whatever order they arrive in', function (): void {
        // Sequence decides compounding, so it is applied rather than trusted.
        $shuffled = $this->calculator->calculate([
            new LineInput(
                quantity: '1',
                unitPrice: '1000.00',
                taxComponents: [$this->further, $this->gst],
            ),
        ]);

        expect($shuffled->taxTotal)->toBeDecimal('215.4000');
    });

    it('charges nothing on a zero-rated supply, and still reports it', function (): void {
        $zeroRated = new TaxComponentRate(
            componentId: 'gst-zero',
            name: 'GST 0% (export)',
            rate: '0',
            sequence: 1,
            accountId: 'gst-output',
        );

        $result = $this->calculator->calculate([
            new LineInput(quantity: '1', unitPrice: '5000.00', taxComponents: [$zeroRated]),
        ]);

        expect($result->taxTotal)->toBeDecimal('0.0000')
            ->and($result->total)->toBeDecimal('5000.0000');

        /*
         * The component is still on the line. A zero-rated supply appears on
         * the return at 0%; an exempt one does not appear at all — and the
         * difference is a filing error, so the row survives.
         */
        expect($result->lines[0]->taxes)->toHaveCount(1);
        expect($result->lines[0]->taxes[0]->taxableAmount)->toBeDecimal('5000.0000');
    });

    it('charges nothing and reports nothing for an exempt line', function (): void {
        // No components at all: outside the tax entirely.
        $result = $this->calculator->calculate([
            new LineInput(quantity: '1', unitPrice: '5000.00'),
        ]);

        expect($result->taxTotal)->toBeDecimal('0.0000')
            ->and($result->lines[0]->taxes)->toBe([])
            ->and($result->taxSummary)->toBe([]);
    });
});

describe('tax-inclusive pricing', function (): void {
    it('extracts the net from a price that contains the tax', function (): void {
        // 1,180 inclusive of 18% is 1,000 + 180.
        $result = $this->calculator->calculate(
            lines: [new LineInput(quantity: '1', unitPrice: '1180.00', taxComponents: [$this->gst])],
            pricesIncludeTax: true,
        );

        expect($result->taxableTotal)->toBeDecimal('1000.0000')
            ->and($result->taxTotal)->toBeDecimal('180.0000')
            // If the user typed 1,180.00, the invoice says 1,180.00.
            ->and($result->total)->toBeDecimal('1180.0000');
    });

    it('extracts across several components', function (): void {
        // 1,310 inclusive of 18% + 13% is 1,000 + 180 + 130.
        $result = $this->calculator->calculate(
            lines: [
                new LineInput(
                    quantity: '1',
                    unitPrice: '1310.00',
                    taxComponents: [$this->gst, $this->pst],
                ),
            ],
            pricesIncludeTax: true,
        );

        expect($result->taxableTotal)->toBeDecimal('1000.0000')
            ->and($result->taxTotal)->toBeDecimal('310.0000')
            ->and($result->total)->toBeDecimal('1310.0000');
    });

    it('divides rather than adds for a compound component', function (): void {
        // 1,215.40 inclusive of 18% then a compound 3%: (1 + 0.18) × 1.03.
        $result = $this->calculator->calculate(
            lines: [
                new LineInput(
                    quantity: '1',
                    unitPrice: '1215.40',
                    taxComponents: [$this->gst, $this->further],
                ),
            ],
            pricesIncludeTax: true,
        );

        expect($result->taxableTotal)->toBeDecimal('1000.0000')
            ->and($result->total)->toBeDecimal('1215.4000');
    });

    it('discounts the price the customer saw, then extracts', function (): void {
        /*
         * The discount was agreed on the inclusive price, so it applies to
         * the extracted net — 10% off 1,180 leaves 1,062 inclusive, which is
         * 900 + 162. Extracting after discounting the inclusive figure gives
         * the same answer; discounting after extraction from an undiscounted
         * price does not.
         */
        $result = $this->calculator->calculate(
            lines: [
                new LineInput(
                    quantity: '1',
                    unitPrice: '1180.00',
                    taxComponents: [$this->gst],
                    discountType: 'percentage',
                    discountValue: '10',
                ),
            ],
            pricesIncludeTax: true,
        );

        expect($result->taxableTotal)->toBeDecimal('900.0000')
            ->and($result->taxTotal)->toBeDecimal('162.0000')
            ->and($result->total)->toBeDecimal('1062.0000');
    });

    it('leaves an untaxed line alone', function (): void {
        $result = $this->calculator->calculate(
            lines: [new LineInput(quantity: '1', unitPrice: '1180.00')],
            pricesIncludeTax: true,
        );

        // Nothing to extract: an inclusive price with no tax IS the net.
        expect($result->total)->toBeDecimal('1180.0000');
    });
});

describe('rounding, on the cases that do not divide cleanly', function (): void {
    it('keeps the document total equal to the sum of its lines', function (): void {
        /*
         * The property that matters more than any individual figure: whatever
         * the arithmetic, a customer adding up the lines must reach the total
         * printed at the bottom.
         */
        $result = $this->calculator->calculate([
            new LineInput(quantity: '3', unitPrice: '33.33', taxComponents: [$this->gst]),
            new LineInput(quantity: '7', unitPrice: '1.11', taxComponents: [$this->gst]),
            new LineInput(quantity: '1', unitPrice: '0.01', taxComponents: [$this->gst]),
        ]);

        $summed = array_reduce(
            $result->lines,
            static fn (string $carry, $line): string => bcadd($carry, $line->total, 4),
            '0',
        );

        expect($result->total)->toBeDecimal($summed);
    });

    it('rounds each tax component once, not the sum', function (): void {
        // 18% of 0.05 is 0.009, which rounds to 0.0090 at four places rather
        // than disappearing.
        $result = $this->calculator->calculate([
            new LineInput(quantity: '1', unitPrice: '0.05', taxComponents: [$this->gst]),
        ]);

        expect($result->taxTotal)->toBeDecimal('0.0090')
            ->and($result->total)->toBeDecimal('0.0590');
    });

    it('rounds half up, consistently', function (): void {
        // 18% of 0.25 is 0.045 exactly — the tie that a rounding mode has to
        // have an opinion about.
        $result = $this->calculator->calculate([
            new LineInput(quantity: '1', unitPrice: '0.25', taxComponents: [$this->gst]),
        ]);

        expect($result->taxTotal)->toBeDecimal('0.0450');
    });

    it('survives a hundred lines without drifting', function (): void {
        /*
         * Rounding per line rather than once per document is the mistake this
         * catches: a hundred lines of 0.005 tax each is 0.50, and rounding
         * each to 0.01 would report 1.00.
         */
        $lines = array_map(
            fn (): LineInput => new LineInput(
                quantity: '1',
                unitPrice: '10.00',
                taxComponents: [$this->gst],
            ),
            range(1, 100),
        );

        $result = $this->calculator->calculate($lines);

        expect($result->subtotal)->toBeDecimal('1000.0000')
            ->and($result->taxTotal)->toBeDecimal('180.0000')
            ->and($result->total)->toBeDecimal('1180.0000');
    });

    it('reports a rate with six decimal places faithfully', function (): void {
        $awkward = new TaxComponentRate(
            componentId: 'awkward',
            name: 'One sixth',
            rate: '0.166667',
            sequence: 1,
        );

        $result = $this->calculator->calculate([
            new LineInput(quantity: '1', unitPrice: '3000.00', taxComponents: [$awkward]),
        ]);

        expect($result->taxTotal)->toBeDecimal('500.0010');
    });
});

describe('what the caller gets back', function (): void {
    it('carries each line reference through, so results match rows', function (): void {
        $result = $this->calculator->calculate([
            new LineInput(quantity: '1', unitPrice: '10', reference: 'line-a'),
            new LineInput(quantity: '1', unitPrice: '20', reference: 'line-b'),
        ]);

        expect(array_map(fn ($line): ?string => $line->reference, $result->lines))
            ->toBe(['line-a', 'line-b']);
    });

    it('sums the tax summary across lines, per component', function (): void {
        $result = $this->calculator->calculate([
            new LineInput(quantity: '1', unitPrice: '1000.00', taxComponents: [$this->gst]),
            new LineInput(quantity: '1', unitPrice: '2000.00', taxComponents: [$this->gst]),
        ]);

        expect($result->taxSummary)->toHaveCount(1);
        expect($result->taxSummary[0]->taxAmount)->toBeDecimal('540.0000')
            ->and($result->taxSummary[0]->taxableAmount)->toBeDecimal('3000.0000');
    });

    it('reports the subtotal gross of discount, so both are reportable', function (): void {
        /*
         * §4.1: revenue is recorded gross with the discount in
         * contra-revenue. Netting the discount into the subtotal here would
         * destroy "gross sales" and "discounts given" permanently — neither
         * could be recovered from the other.
         */
        $result = $this->calculator->calculate([
            new LineInput(
                quantity: '1',
                unitPrice: '1000.00',
                discountType: 'percentage',
                discountValue: '10',
            ),
        ]);

        expect($result->subtotal)->toBeDecimal('1000.0000')
            ->and($result->discountTotal)->toBeDecimal('100.0000')
            ->and($result->taxableTotal)->toBeDecimal('900.0000');
    });
});
