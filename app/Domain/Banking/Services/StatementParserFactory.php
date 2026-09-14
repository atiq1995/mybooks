<?php

declare(strict_types=1);

namespace App\Domain\Banking\Services;

use App\Domain\Banking\Enums\StatementFormat;
use App\Domain\Banking\Exceptions\StatementUnreadable;

/**
 * The right parser for a file.
 *
 * The format is taken from what the person chose, falling back to the file's
 * extension and finally to what the contents look like. Sniffing is last
 * rather than first on purpose: an explicit choice is information, and
 * overriding it with a guess would leave somebody unable to correct a
 * misidentified file.
 */
final readonly class StatementParserFactory
{
    public function __construct(
        private CsvStatementParser $csv,
        private OfxStatementParser $ofx,
        private QifStatementParser $qif,
    ) {}

    public function for(StatementFormat $format): StatementParser
    {
        return match ($format) {
            StatementFormat::Csv => $this->csv,
            StatementFormat::Ofx => $this->ofx,
            StatementFormat::Qif => $this->qif,
        };
    }

    /**
     * Decide the format of a file nobody named one for.
     */
    public function detect(string $filename, string $contents): StatementFormat
    {
        $byName = StatementFormat::fromFilename($filename);

        if ($byName !== null) {
            return $byName;
        }

        return self::sniff($contents) ?? throw StatementUnreadable::unknownFormat($filename);
    }

    /**
     * What the first few hundred bytes say the file is.
     */
    public static function sniff(string $contents): ?StatementFormat
    {
        $head = mb_substr($contents, 0, 2048);

        if (stripos($head, 'OFXHEADER') !== false || stripos($head, '<OFX>') !== false) {
            return StatementFormat::Ofx;
        }

        if (preg_match('~^\s*!Type:~mi', $head) === 1) {
            return StatementFormat::Qif;
        }

        // A comma or semicolon on a line with something that reads as a date
        // heading is as much as CSV can be recognised by.
        if (preg_match('~(date|narration|particulars|description)~i', $head) === 1
            && preg_match('~[,;\t|]~', $head) === 1) {
            return StatementFormat::Csv;
        }

        return null;
    }
}
