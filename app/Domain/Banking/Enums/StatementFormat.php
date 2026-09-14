<?php

declare(strict_types=1);

namespace App\Domain\Banking\Enums;

/**
 * The statement file formats we read.
 *
 * Three, because between them they cover what banks actually hand out: CSV
 * from almost every online banking portal, OFX from the ones with a
 * "download for accounting software" button, and QIF from the older ones that
 * never stopped.
 */
enum StatementFormat: string
{
    case Csv = 'csv';
    case Ofx = 'ofx';
    case Qif = 'qif';

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Ofx => 'OFX / QFX',
            self::Qif => 'QIF',
        };
    }

    /**
     * The format a filename suggests.
     *
     * A suggestion only — the extension is whatever the person who saved the
     * file typed. The parser still fails loudly if the contents are not what
     * the extension claimed, which is the check that matters.
     */
    public static function fromFilename(string $filename): ?self
    {
        return match (mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'csv', 'txt' => self::Csv,
            'ofx', 'qfx' => self::Ofx,
            'qif' => self::Qif,
            default => null,
        };
    }
}
