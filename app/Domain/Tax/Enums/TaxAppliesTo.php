<?php

declare(strict_types=1);

namespace App\Domain\Tax\Enums;

/**
 * Which documents a tax can appear on.
 *
 * `Withholding` is the odd one out on purpose: it never appears on a document
 * at all. It is a deduction at payment time that changes how a balance is
 * settled, never what the invoice says — see ACCOUNTING_RULES.md §5.
 */
enum TaxAppliesTo: string
{
    case Sales = 'sales';
    case Purchase = 'purchase';
    case Both = 'both';
    case Withholding = 'withholding';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Sales only',
            self::Purchase => 'Purchases only',
            self::Both => 'Sales and purchases',
            self::Withholding => 'Withholding (at payment)',
        };
    }

    public function onSalesDocuments(): bool
    {
        return $this === self::Sales || $this === self::Both;
    }

    public function onPurchaseDocuments(): bool
    {
        return $this === self::Purchase || $this === self::Both;
    }

    public function isWithholding(): bool
    {
        return $this === self::Withholding;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
