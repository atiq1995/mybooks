<?php

declare(strict_types=1);

namespace App\Domain\Banking\Services;

use App\Domain\Banking\Data\ParsedStatementLine;
use App\Domain\Banking\Exceptions\StatementUnreadable;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * QIF — the oldest of the three, and still what some banks offer.
 *
 * A record is a run of lines, each beginning with a one-letter field code,
 * terminated by a line containing only `^`:
 *
 *   D30/06/2026     date
 *   T-12,500.00     amount, signed
 *   PKarachi Fuels  payee
 *   MDiesel, van    memo
 *   N100234         cheque or reference number
 *
 * The format's weakness is dates: it has no convention at all, and the same
 * bank's export can be day-first one year and ISO the next. The same
 * whole-file inference the CSV parser uses applies here, and for the same
 * reason — a per-record guess would produce one file with two conventions in
 * it.
 */
final class QifStatementParser implements StatementParser
{
    /**
     * @return list<ParsedStatementLine>
     */
    public function parse(string $contents, string $filename): array
    {
        $records = $this->records($contents);

        if ($records === []) {
            throw StatementUnreadable::notQif($filename);
        }

        $dayFirst = $this->inferDayFirst($records);

        $lines = [];

        foreach ($records as $index => $record) {
            if (! isset($record['D'], $record['T'])) {
                continue;
            }

            try {
                $amount = BigDecimal::of($this->cleanAmount($record['T']))
                    ->toScale(4, RoundingMode::HalfUp);
            } catch (MathException) {
                throw StatementUnreadable::unreadableAmount($record['T'], $index + 1);
            }

            if ($amount->isZero()) {
                continue;
            }

            $payee = $record['P'] ?? null;
            $memo = $record['M'] ?? null;

            $description = match (true) {
                $payee !== null && $memo !== null && $payee !== $memo => "{$payee} — {$memo}",
                $payee !== null => $payee,
                $memo !== null => $memo,
                default => 'Bank transaction',
            };

            $lines[] = new ParsedStatementLine(
                date: $this->parseDate($record['D'], $dayFirst, $index + 1),
                description: mb_substr($description, 0, 500),
                amount: (string) $amount,
                reference: isset($record['N']) ? mb_substr($record['N'], 0, 160) : null,
                payee: $payee === null ? null : mb_substr($payee, 0, 200),
            );
        }

        if ($lines === []) {
            throw StatementUnreadable::empty($filename);
        }

        return $lines;
    }

    /**
     * @return list<array<string, string>>
     */
    private function records(string $contents): array
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        $records = [];
        $current = [];

        foreach (explode("\n", $contents) as $line) {
            $line = rtrim($line);

            if ($line === '') {
                continue;
            }

            if ($line === '^') {
                if ($current !== []) {
                    $records[] = $current;
                }

                $current = [];

                continue;
            }

            // !Type:Bank and friends — a header, not a transaction.
            if (str_starts_with($line, '!')) {
                continue;
            }

            $code = mb_substr($line, 0, 1);
            $value = trim(mb_substr($line, 1));

            if ($value === '') {
                continue;
            }

            /*
             * Split lines (S/E/$) describe how one transaction divides across
             * categories. Only the first of each code is kept, so a split
             * does not overwrite the transaction's own payee or memo.
             */
            if (! array_key_exists($code, $current)) {
                $current[$code] = $value;
            }
        }

        // A file whose last record has no terminating ^ still has that record.
        if ($current !== []) {
            $records[] = $current;
        }

        return $records;
    }

    /**
     * @param  list<array<string, string>>  $records
     */
    private function inferDayFirst(array $records): bool
    {
        foreach ($records as $record) {
            if (! isset($record['D'])) {
                continue;
            }

            if (preg_match('~^(\d{1,2})[/\-.\' ](\d{1,2})~', trim($record['D']), $m) !== 1) {
                continue;
            }

            if ((int) $m[1] > 12) {
                return true;
            }

            if ((int) $m[2] > 12) {
                return false;
            }
        }

        return true;
    }

    private function parseDate(string $raw, bool $dayFirst, int $index): Carbon
    {
        /*
         * Quicken wrote years as 'YY for 2000s and  YY for 1900s, and some
         * exports still do. Normalising the apostrophe to a separator lets
         * the ordinary formats below handle it.
         */
        $value = trim(str_replace("'", '/', $raw));
        $value = (string) preg_replace('/\s+/', '', $value);

        $formats = $dayFirst
            ? ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'd-m-y', 'Y/m/d']
            : ['Y-m-d', 'm/d/Y', 'm-d-Y', 'm.d.Y', 'm/d/y', 'm-d-y', 'Y/m/d'];

        foreach ($formats as $format) {
            /*
             * Round-tripped rather than merely parsed. Carbon will happily
             * read 31/02/2026 as the third of March, and a statement line
             * silently moved to another month is the kind of error that only
             * turns up when a period refuses to reconcile.
             */
            try {
                $parsed = Carbon::createFromFormat($format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($parsed instanceof Carbon && $parsed->format($format) === $value) {
                return $parsed->startOfDay();
            }
        }

        throw StatementUnreadable::unreadableDate($raw, $index);
    }

    private function cleanAmount(string $raw): string
    {
        $value = (string) preg_replace('/[^0-9.\-+]/', '', trim($raw));

        return $value === '' ? '0' : $value;
    }
}
