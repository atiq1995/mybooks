<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Models\SalesDocument;
use App\Domain\Sales\Models\SalesDocumentLine;
use App\Domain\Sales\Models\SalesDocumentLineTax;
use App\Domain\Tax\Data\DocumentResult;
use App\Domain\Tax\Data\LineInput;
use App\Domain\Tax\Data\TaxComponentRate;
use App\Domain\Tax\Models\Tax;
use App\Domain\Tax\TaxCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;

/**
 * The bridge between stored lines and the pure tax engine.
 *
 * Its whole job is to resolve tax components as at the document's own date,
 * hand the figures to {@see TaxCalculator}, and write what comes back onto
 * the rows. It contains no arithmetic of its own — every figure below is one
 * the calculator produced.
 *
 * Keeping the resolution here rather than in the calculator is what lets the
 * calculator stay pure, and the resolution is the only part that needs a
 * database: which rate was in force on 15 September is a question about
 * stored versions, not about arithmetic.
 *
 * @see ACCOUNTING_RULES.md §5
 */
final readonly class SalesDocumentCalculator
{
    public function __construct(
        private TaxCalculator $calculator,
    ) {}

    /**
     * Recalculate a document and persist every figure.
     *
     * Called whenever a draft changes. Never for an issued document: those
     * keep the figures they were posted with, and a recalculation from rates
     * that have since changed is precisely how a document stops agreeing with
     * its journal entry.
     */
    public function recalculate(SalesDocument $document): DocumentResult
    {
        $lines = $document->lines()->get();

        if ($lines->isEmpty()) {
            $this->zeroTotals($document);

            return new DocumentResult([], '0.0000', '0.0000', '0.0000', '0.0000', '0.0000', []);
        }

        // Resolved once per tax, not once per line: a ten-line invoice using
        // one tax is one lookup.
        $components = $this->resolveComponents(
            $document,
            array_values($lines->map(static fn (SalesDocumentLine $line): ?string => $line->tax_id)->all()),
        );

        $inputs = array_values(
            $lines
                ->map(fn (SalesDocumentLine $line): LineInput => new LineInput(
                    quantity: $line->quantity,
                    unitPrice: $line->unit_price,
                    taxComponents: $line->tax_id === null ? [] : ($components[$line->tax_id] ?? []),
                    discountType: self::discountType($line->discount_type),
                    discountValue: $line->discount_value,
                    // The line's id, so results can be matched back to rows
                    // without depending on order.
                    reference: $line->id,
                ))
                ->all(),
        );

        $result = $this->calculator->calculate(
            lines: $inputs,
            documentDiscountType: self::discountType($document->discount_type),
            documentDiscountValue: $document->discount_value,
            pricesIncludeTax: $document->prices_include_tax,
        );

        /** @var array<string, SalesDocumentLine> $byId */
        $byId = $lines->keyBy('id')->all();

        $this->persist($document, $byId, $result);

        return $result;
    }

    /**
     * Narrow a stored discount type to what the calculator accepts.
     *
     * A CHECK constraint already limits the column to these two values, so
     * anything else means the row was written outside the application — in
     * which case treating it as "no discount" is safer than guessing.
     *
     * @return 'percentage'|'amount'|null
     */
    private static function discountType(?string $type): ?string
    {
        return match ($type) {
            'percentage' => 'percentage',
            'amount' => 'amount',
            default => null,
        };
    }

    /**
     * The tax components in force on this document's date, keyed by tax.
     *
     * As at the ISSUE DATE, not today. A rate that changed on 1 July must not
     * touch a June invoice being edited in August.
     *
     * @param  array<int, string|null>  $taxIds
     * @return array<string, list<TaxComponentRate>>
     */
    private function resolveComponents(SalesDocument $document, array $taxIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $taxIds,
            static fn (?string $id): bool => is_string($id),
        )));

        if ($ids === []) {
            return [];
        }

        $resolved = [];

        foreach (Tax::query()->whereKey($ids)->get() as $tax) {
            $resolved[$tax->id] = array_map(
                static fn ($component) => $component->toRate('sales'),
                $tax->componentsOn($document->issue_date),
            );
        }

        return $resolved;
    }

    /**
     * Write the calculator's figures onto the rows.
     *
     * @param  array<string, SalesDocumentLine>  $lines
     */
    private function persist(SalesDocument $document, array $lines, DocumentResult $result): void
    {
        $rate = BigDecimal::of($document->exchange_rate);

        // Replaced wholesale rather than reconciled: the breakdown is derived
        // data, and diffing it would be more code for the same answer.
        SalesDocumentLineTax::query()->where('sales_document_id', $document->id)->delete();

        foreach ($result->lines as $computed) {
            $line = $lines[(string) $computed->reference] ?? null;

            if ($line === null) {
                continue;
            }

            $line->forceFill([
                'gross' => $computed->gross,
                'discount_amount' => $computed->discountAmount,
                'net' => $computed->net,
                'document_discount_amount' => $computed->documentDiscountAmount,
                'taxable' => $computed->taxable,
                'tax_total' => $computed->taxTotal,
                'total' => $computed->total,
            ])->save();

            foreach ($computed->taxes as $tax) {
                $lineTax = new SalesDocumentLineTax;

                $lineTax->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $document->organization_id,
                    'sales_document_id' => $document->id,
                    'sales_document_line_id' => $line->id,
                    'tax_component_id' => $tax->componentId,
                    // Copied, so the tax return stays readable after a rename
                    // or a rate change.
                    'component_name' => $tax->name,
                    'rate' => $tax->rate,
                    'is_compound' => $tax->isCompound,
                    'taxable_amount' => $tax->taxableAmount,
                    'tax_amount' => $tax->taxAmount,
                    'tax_amount_base' => $this->toBase($tax->taxAmount, $rate),
                    'account_id' => $tax->accountId,
                ])->save();
            }
        }

        $document->forceFill([
            'subtotal' => $result->subtotal,
            'discount_total' => $result->discountTotal,
            'tax_total' => $result->taxTotal,
            'total' => $result->total,
            'subtotal_base' => $this->toBase($result->subtotal, $rate),
            'discount_total_base' => $this->toBase($result->discountTotal, $rate),
            'tax_total_base' => $this->toBase($result->taxTotal, $rate),
            'total_base' => $this->toBase($result->total, $rate),
        ])->save();
    }

    private function zeroTotals(SalesDocument $document): void
    {
        SalesDocumentLineTax::query()->where('sales_document_id', $document->id)->delete();

        $document->forceFill([
            'subtotal' => '0',
            'discount_total' => '0',
            'tax_total' => '0',
            'total' => '0',
            'subtotal_base' => '0',
            'discount_total_base' => '0',
            'tax_total_base' => '0',
            'total_base' => '0',
        ])->save();
    }

    private function toBase(string $amount, BigDecimal $rate): string
    {
        return (string) BigDecimal::of($amount)
            ->multipliedBy($rate)
            ->toScale(4, RoundingMode::HalfUp);
    }
}
