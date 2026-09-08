<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Services;

use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseLine;
use App\Domain\Expenses\Models\ExpenseLineTax;
use App\Domain\Tax\Data\DocumentResult;
use App\Domain\Tax\Data\LineInput;
use App\Domain\Tax\Data\TaxComponentRate;
use App\Domain\Tax\Models\Tax;
use App\Domain\Tax\TaxCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;

/**
 * The bridge between stored expense lines and the pure tax engine.
 *
 * The third of these — sales, purchases, expenses — and deliberately still
 * its own class rather than a generic one parameterised by table and column
 * names. Each is a hundred lines of straight-line code that reads as what it
 * is; a shared version would need a mapping object per module and would turn
 * three readable files into one indirect one plus three configurations. The
 * arithmetic they all share is already in one place: {@see TaxCalculator}.
 *
 * What differs here from purchases is only that an expense has no
 * document-level discount — a receipt total is a receipt total — so there is
 * nothing to apportion.
 *
 * @see ACCOUNTING_RULES.md §4.8, §5
 */
final readonly class ExpenseCalculator
{
    public function __construct(
        private TaxCalculator $calculator,
    ) {}

    /**
     * Recalculate an expense and persist every figure.
     *
     * Called whenever a draft changes; never for an approved expense, which
     * keeps the figures it posted with.
     */
    public function recalculate(Expense $expense): DocumentResult
    {
        $lines = $expense->lines()->get();

        if ($lines->isEmpty()) {
            $this->zeroTotals($expense);

            return new DocumentResult([], '0.0000', '0.0000', '0.0000', '0.0000', '0.0000', []);
        }

        // Resolved once per tax, not once per line.
        $components = $this->resolveComponents(
            $expense,
            array_values($lines->map(static fn (ExpenseLine $line): ?string => $line->tax_id)->all()),
        );

        $inputs = array_values(
            $lines
                ->map(fn (ExpenseLine $line): LineInput => new LineInput(
                    // For a mileage line these are the distance and the rate.
                    // The multiplication is the same, which is the whole
                    // reason mileage needs no special case.
                    quantity: $line->quantity,
                    unitPrice: $line->unit_price,
                    taxComponents: $line->tax_id === null ? [] : ($components[$line->tax_id] ?? []),
                    reference: $line->id,
                ))
                ->all(),
        );

        $result = $this->calculator->calculate(
            lines: $inputs,
            // No document discount: an expense is what was spent.
            pricesIncludeTax: $expense->prices_include_tax,
        );

        /** @var array<string, ExpenseLine> $byId */
        $byId = $lines->keyBy('id')->all();

        $this->persist($expense, $byId, $result);

        return $result;
    }

    /**
     * The tax components in force on the expense's date, keyed by tax, each
     * carrying its INPUT account.
     *
     * As at the date the money was spent, not today.
     *
     * @param  array<int, string|null>  $taxIds
     * @return array<string, list<TaxComponentRate>>
     */
    private function resolveComponents(Expense $expense, array $taxIds): array
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
                $tax->componentsOn($expense->expense_date),
            );
        }

        return $resolved;
    }

    /**
     * @param  array<string, ExpenseLine>  $lines
     */
    private function persist(Expense $expense, array $lines, DocumentResult $result): void
    {
        $rate = BigDecimal::of($expense->exchange_rate);

        ExpenseLineTax::query()->where('expense_id', $expense->id)->delete();

        $claimable = BigDecimal::zero();

        foreach ($result->lines as $computed) {
            $line = $lines[(string) $computed->reference] ?? null;

            if ($line === null) {
                continue;
            }

            $line->forceFill([
                'gross' => $computed->gross,
                'net' => $computed->net,
                'taxable' => $computed->taxable,
                'tax_total' => $computed->taxTotal,
                'total' => $computed->total,
            ])->save();

            if ($line->tax_is_claimable) {
                $claimable = $claimable->plus(BigDecimal::of($computed->taxTotal));
            }

            foreach ($computed->taxes as $tax) {
                $lineTax = new ExpenseLineTax;

                $lineTax->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $expense->organization_id,
                    'expense_id' => $expense->id,
                    'expense_line_id' => $line->id,
                    'tax_component_id' => $tax->componentId,
                    'component_name' => $tax->name,
                    'rate' => $tax->rate,
                    'is_compound' => $tax->isCompound,
                    'is_claimable' => $line->tax_is_claimable,
                    'taxable_amount' => $tax->taxableAmount,
                    'tax_amount' => $tax->taxAmount,
                    'tax_amount_base' => $this->toBase($tax->taxAmount, $rate),
                    'account_id' => $tax->accountId,
                ])->save();
            }
        }

        $claimableTotal = (string) $claimable->toScale(4, RoundingMode::HalfUp);

        $expense->forceFill([
            'subtotal' => $result->subtotal,
            'tax_total' => $result->taxTotal,
            'tax_claimable_total' => $claimableTotal,
            'total' => $result->total,
            'subtotal_base' => $this->toBase($result->subtotal, $rate),
            'tax_total_base' => $this->toBase($result->taxTotal, $rate),
            'tax_claimable_total_base' => $this->toBase($claimableTotal, $rate),
            'total_base' => $this->toBase($result->total, $rate),
        ])->save();
    }

    private function zeroTotals(Expense $expense): void
    {
        ExpenseLineTax::query()->where('expense_id', $expense->id)->delete();

        $expense->forceFill([
            'subtotal' => '0',
            'tax_total' => '0',
            'tax_claimable_total' => '0',
            'total' => '0',
            'subtotal_base' => '0',
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
