<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exceptions;

use RuntimeException;

/**
 * A statement file could not be read.
 *
 * Every message here is shown to whoever uploaded the file, so each one says
 * what is wrong with THIS file rather than what the format is in general.
 * "Could not parse" tells somebody staring at a bank download nothing they
 * can act on.
 */
final class StatementUnreadable extends RuntimeException
{
    public static function empty(string $filename): self
    {
        return new self(
            "{$filename} has no transactions in it. If the download was filtered by date, ".
            'check the range it covered.'
        );
    }

    public static function unknownFormat(string $filename): self
    {
        return new self(
            "The format of {$filename} could not be determined from its name. ".
            'Choose CSV, OFX or QIF explicitly and upload it again.'
        );
    }

    public static function missingColumn(string $what): self
    {
        return new self(
            "This CSV has no {$what} column that could be recognised. ".
            'Rename the heading, or export again including it.'
        );
    }

    public static function unreadableDate(string $value, int $line): self
    {
        return new self(
            "Line {$line} has a date of \"{$value}\", which is not a date in any format ".
            'this import understands. Export with ISO dates (2026-06-30) if the bank offers it.'
        );
    }

    public static function unreadableAmount(string $value, int $line): self
    {
        return new self("Line {$line} has an amount of \"{$value}\", which is not a number.");
    }

    public static function notOfx(string $filename): self
    {
        return new self(
            "{$filename} does not contain any OFX transactions. A file saved from a browser ".
            'is often the HTML page rather than the statement itself.'
        );
    }

    public static function notQif(string $filename): self
    {
        return new self("{$filename} does not look like a QIF file: it has no transaction records.");
    }

    /**
     * A statement belonging to a different account.
     *
     * Worth its own message because it is the most consequential import
     * mistake available: nothing about the file is malformed, and the rows
     * would import perfectly well against the wrong account.
     */
    public static function accountMismatch(string $expected, string $found): self
    {
        return new self(
            "This statement is for account {$found}, but {$expected} was selected. ".
            'Choose the matching account, or import the right file.'
        );
    }
}
