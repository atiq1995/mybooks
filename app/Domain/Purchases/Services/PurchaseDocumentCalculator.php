<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Services;

use App\Domain\Purchases\Models\PurchaseDocument;
use App\Domain\Purchases\Models\PurchaseDocumentLine;
use App\Domain\Purchases\Models\PurchaseDocumentLineTax;
use App\Domain\Tax\Data\DocumentResult;
use App\Domain\Tax\Data\LineInput;
use App\Domain\Tax\Data\TaxComponentRate;
use App\Domain\Tax\Models\Tax;
use App\Domain\Tax\TaxCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;

/**
 * The bridge between stored purchase lines and the pure tax engine.
 *
 * The same job as its sales counterpart — resolve components as at the
 * document's own date, hand the figures to {@see TaxCalculator}, write back
 * what comes out — and it contains no arithmetic of its own beyond summing.
 *
 * Two things differ, and both matter:
 *
 * The components resolve to their INPUT account, not their output account.
 * The same 18% GST is a liability when we charge it and a receivable when we
 * are charged it, and the account is what says which.
 *
 * The claimable total is computed alongside the tax total, because §4.6 posts
 * them to different places. It is a sum over the lines that may reclaim
 * rather than a share of the tax, so a document with one blocked line and
 * nine claimable ones states both figures exactly.
 *
 * @see ACCOUNTING_RULES.md §4.6, §5
 */
final readonly class PurchaseDocumentCalculator
{
    public function __construct(
        private TaxCalculator $calculator,
    ) {}

    /**
     * Recalculate a document and persist every figure.
     *
     * Called whenever a draft changes; never for an approved document, which
     * keeps the figures it posted with.
     */
    public function recalculate(PurchaseDocument $document): DocumentResult
    {
        $lines = $document->lines()->get();

        if ($lines->isEmpty()) {
            $this->zeroTotals($document);

            return new DocumentResult([], '0.0000', '0.0000', '0.0000', '0.0000', '0.0000', []);
        }

        // Resolved once per tax, not once per line.
        $components = $this->resolveComponents(
            $document,
            array_values($lines->map(static fn (PurchaseDocumentLine $line): ?string => $line->tax_id)->all()),
        );

        $inputs = array_values(
            $lines
                ->map(fn (PurchaseDocumentLine $line): LineInput => new LineInput(
                    quantity: $line->quantity,
                    unitPrice: $line->unit_price,
                    taxComponents: $line->tax_id === null ? [] : ($components[$line->tax_id] ?? []),
                    discountType: self::discountType($line->discount_type),
                    discountValue: $line->discount_value,
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

        /** @var array<string, PurchaseDocumentLine> $byId */
        $byId = $lines->keyBy('id')->all();

        $this->persist($document, $byId, $result);

        return $result;
    }

    /**
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
     * The tax components in force on this document's date, keyed by tax, each
     * carrying its INPUT account.
     *
     * As at the ISSUE DATE, not today: a rate that changed on 1 July must not
     * touch a June bill being entered in August.
     *
     * @param  array<int, string|null>  $taxIds
     * @return array<string, list<TaxComponentRate>>
     */
    private function resolveComponents(PurchaseDocument $document, array $taxIds): array
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
                static fn ($component) => $component->toRate('purchase'),
                $tax->componentsOn($document->issue_date),
            );
        }

        return $resolved;
    }

    /**
     * Write the calculator's figures onto the rows.
     *
     * @param  array<string, PurchaseDocumentLine>  $lines
     */
    private function persist(PurchaseDocument $document, array $lines, DocumentResult $result): void
    {
        $rate = BigDecimal::of($document->exchange_rate);

        PurchaseDocumentLineTax::query()
            ->where('purchase_document_id', $document->id)
            ->delete();

        $claimable = BigDecimal::zero();

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

            if ($line->tax_is_claimable) {
                $claimable = $claimable->plus(BigDecimal::of($computed->taxTotal));
            }

            foreach ($computed->taxes as $tax) {
                $lineTax = new PurchaseDocumentLineTax;

                $lineTax->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $document->organization_id,
                    'purchase_document_id' => $document->id,
                    'purchase_document_line_id' => $line->id,
                    'tax_component_id' => $tax->componentId,
                    // Copied, so the return stays readable after a rename or
                    // a rate change.
                    'component_name' => $tax->name,
                    'rate' => $tax->rate,
                    'is_compound' => $tax->isCompound,
                    // Copied down from the line, so the input-tax return can
                    // be filed from these rows alone.
                    'is_claimable' => $line->tax_is_claimable,
                    'taxable_amount' => $tax->taxableAmount,
                    'tax_amount' => $tax->taxAmount,
                    'tax_amount_base' => $this->toBase($tax->taxAmount, $rate),
                    'account_id' => $tax->accountId,
                ])->save();
            }
        }

        $claimableTotal = (string) $claimable->toScale(4, RoundingMode::HalfUp);

        $document->forceFill([
            'subtotal' => $result->subtotal,
            'discount_total' => $result->discountTotal,
            'tax_total' => $result->taxTotal,
            'tax_claimable_total' => $claimableTotal,
            'total' => $result->total,
            'subtotal_base' => $this->toBase($result->subtotal, $rate),
            'discount_total_base' => $this->toBase($result->discountTotal, $rate),
            'tax_total_base' => $this->toBase($result->taxTotal, $rate),
            'tax_claimable_total_base' => $this->toBase($claimableTotal, $rate),
            'total_base' => $this->toBase($result->total, $rate),
        ])->save();
    }

    private function zeroTotals(PurchaseDocument $document): void
    {
        PurchaseDocumentLineTax::query()
            ->where('purchase_document_id', $document->id)
            ->delete();

        $document->forceFill([
            'subtotal' => '0',
            'discount_total' => '0',
            'tax_total' => '0',
            'tax_claimable_total' => '0',
            'total' => '0',
            'subtotal_base' => '0',
            'discount_total_base' => '0',
            'tax_total_base' => '0',
            'tax_claimable_total_base' => '0',
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
