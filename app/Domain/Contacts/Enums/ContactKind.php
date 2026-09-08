<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Enums;

/**
 * Which side of the ledger a contact appears on.
 *
 * `Both` exists because the same business often is: you buy from your printer
 * and invoice them for consultancy. Two rows would mean two balances and two
 * sets of tax numbers that drift apart.
 */
enum ContactKind: string
{
    case Customer = 'customer';
    case Vendor = 'vendor';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Vendor => 'Vendor',
            self::Both => 'Customer and vendor',
        };
    }

    public function isCustomer(): bool
    {
        return $this !== self::Vendor;
    }

    public function isVendor(): bool
    {
        return $this !== self::Customer;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
