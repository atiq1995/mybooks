<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * Goods or a service.
 *
 * An invoice line treats them identically. What differs is inventory: only
 * goods can be tracked, and only tracked goods post a cost of sale.
 */
enum ItemKind: string
{
    case Goods = 'goods';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Goods => 'Goods',
            self::Service => 'Service',
        };
    }

    public function canBeTracked(): bool
    {
        return $this === self::Goods;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
