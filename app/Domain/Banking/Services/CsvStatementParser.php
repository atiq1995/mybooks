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
 * CSV, which in practice means "whatever this particular bank exports".
 *
 * There is no CSV statement standard, so this parser is mostly about
 * recognising the same idea under different names: a date column that might
 * be "Date", "Txn Date" or "Value Date"; a single signed amount, or a debit
 * and a credit column; a description that might be "Narration" or
 * "Particulars".
 *
 * Two decisions in here matter more than the rest.
 *
 * **Date order is inferred from the whole file, not guessed per row.**
 * 03/04/2026 is the third of April to most of the world and the fourth of
 * March in the United States, and a row-by-row guess would silently produce a
 * statement with two different conventions in it. So every date is collected
 * first: if any row has a first component above 12 the file is day-first, if
 * any has a second component above 12 it is month-first, and if the file is
 * ambiguous from end to end it is read day-first — which is the convention
 * where this product is used, and is at least applied consistently.
 *
 * **A row that cannot be read stops the import.** Not skipped: a statement
 * with a row missing is worse than no statement at all, because it will
 * reconcile to a difference nobody can explain.
 */
final class CsvStatementParser implements StatementParser
{
    /** @var list<string> */
    private const DATE_HEADINGS = [
        'date', 'transaction date', 'txn date', 'tran date', 'trans date',
        'value date', 'posting date', 'post date', 'booking date', 'entry date',
    ];

    /** @var list<string> */
    private const DESCRIPTION_HEADINGS = [
        'description', 'narration', 'details', 'particulars', 'transaction details',
        'remarks', 'memo', 'transaction', 'transaction description', 'narrative',
    ];

    /** @var list<string> */
    private const REFERENCE_HEADINGS = [
        'reference', 'ref', 'ref no', 'reference no', 'cheque', 'cheque no', 'chq no',
        'check number', 'instrument no', 'transaction id', 'txn id', 'transaction ref',
    ];

    /** @var list<string> */
    private const PAYEE_HEADINGS = [
        'payee', 'merchant', 'beneficiary', 'counterparty', 'name', 'to account name',
    ];

    /** @var list<string> */
    private const DEBIT_HEADINGS = [
        'debit', 'debit amount', 'withdrawal', 'withdrawals', 'withdrawal amount',
        'paid out', 'money out', 'dr', 'dr amount', 'out',
    ];

    /** @var list<string> */
    private const CREDIT_HEADINGS = [
        'credit', 'credit amount', 'deposit', 'deposits', 'deposit amount',
        'paid in', 'money in', 'cr', 'cr amount', 'in',
    ];

    /** @var list<string> */
    private const AMOUNT_HEADINGS = [
        'amount', 'value', 'transaction amount', 'amount (pkr)', 'signed amount',
    ];

    /** @var list<string> */
    private const BALANCE_HEADINGS = [
        'balance', 'running balance', 'closing balance', 'balance amount', 'available balance',
    ];

    /**
     * @return list<ParsedStatementLine>
     */
    public function parse(string $contents, string $filename): array
    {
        $rows = $this->rows($contents);

        if ($rows === []) {
            throw StatementUnreadable::empty($filename);
        }

        $headerIndex = $this->findHeader($rows);
        $columns = $this->mapColumns($rows[$headerIndex]);

        $dataRows = array_slice($rows, $headerIndex + 1);

        // Pass one: decide what 03/04/2026 means in THIS file.
        $dayFirst = $this->inferDayFirst($dataRows, $columns['date']);

        $lines = [];

        foreach ($dataRows as $offset => $row) {
            // The line number a person would count to in a text editor.
            $lineNo = $headerIndex + $offset + 2;

            if ($this->isBlank($row)) {
                continue;
            }

            $rawDate = $this->value($row, $columns['date']);

            if ($rawDate === null) {
                continue;
            }

            $amount = $this->amountFor($row, $columns, $lineNo);

            /*
             * A zero row is a marker, not a transaction — an opening-balance
             * line, or a subtotal the export threw in. Importing it would
             * hand the matcher something no entry can ever clear.
             */
            if ($amount->isZero()) {
                continue;
            }

            $description = $this->value($row, $columns['description'])
                ?? $this->value($row, $columns['payee'])
                ?? 'Bank transaction';

            $lines[] = new ParsedStatementLine(
                date: $this->parseDate($rawDate, $dayFirst, $lineNo),
                description: mb_substr($description, 0, 500),
                amount: (string) $amount,
                reference: $this->trimToNull($this->value($row, $columns['reference']), 160),
                payee: $this->trimToNull($this->value($row, $columns['payee']), 200),
                balance: $columns['balance'] === null
                    ? null
                    : $this->optionalDecimal($this->value($row, $columns['balance'])),
            );
        }

        if ($lines === []) {
            throw StatementUnreadable::empty($filename);
        }

        return $lines;
    }

    /**
     * @return list<list<string>>
     */
    private function rows(string $contents): array
    {
        // A BOM on the first heading is why "Date" sometimes fails to match.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        $delimiter = $this->delimiter($contents);

        $rows = [];

        foreach (explode("\n", $contents) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $parsed = str_getcsv($line, $delimiter, '"', '\\');

            $rows[] = array_map(
                static fn (?string $cell): string => trim((string) $cell),
                $parsed,
            );
        }

        return $rows;
    }

    /**
     * Whichever separator appears most on the first non-empty line.
     */
    private function delimiter(string $contents): string
    {
        $firstLine = strtok($contents, "\n");

        if ($firstLine === false) {
            return ',';
        }

        $best = ',';
        $bestCount = 0;

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = substr_count($firstLine, $candidate);

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * The first row that looks like headings rather than data.
     *
     * Banks put account numbers, addresses and disclaimers above the table,
     * so "row zero is the header" is wrong more often than it is right.
     *
     * @param  list<list<string>>  $rows
     */
    private function findHeader(array $rows): int
    {
        foreach ($rows as $index => $row) {
            foreach ($row as $cell) {
                if (in_array(mb_strtolower(trim($cell)), self::DATE_HEADINGS, true)) {
                    return $index;
                }
            }
        }

        throw StatementUnreadable::missingColumn('date');
    }

    /**
     * @param  list<string>  $header
     * @return array{date: int, description: ?int, reference: ?int, payee: ?int, debit: ?int, credit: ?int, amount: ?int, balance: ?int}
     */
    private function mapColumns(array $header): array
    {
        $find = static function (array $candidates) use ($header): ?int {
            foreach ($header as $index => $cell) {
                if (in_array(mb_strtolower(trim($cell)), $candidates, true)) {
                    return $index;
                }
            }

            return null;
        };

        $date = $find(self::DATE_HEADINGS);

        if ($date === null) {
            throw StatementUnreadable::missingColumn('date');
        }

        $debit = $find(self::DEBIT_HEADINGS);
        $credit = $find(self::CREDIT_HEADINGS);
        $amount = $find(self::AMOUNT_HEADINGS);

        /*
         * One of the two shapes has to be present. Without either, every row
         * would import as zero — which the database would reject line by
         * line, leaving somebody to work out from a constraint name that
         * their export had no amounts in it.
         */
        if ($amount === null && $debit === null && $credit === null) {
            throw StatementUnreadable::missingColumn('amount, debit or credit');
        }

        return [
            'date' => $date,
            'description' => $find(self::DESCRIPTION_HEADINGS),
            'reference' => $find(self::REFERENCE_HEADINGS),
            'payee' => $find(self::PAYEE_HEADINGS),
            'debit' => $debit,
            'credit' => $credit,
            'amount' => $amount,
            'balance' => $find(self::BALANCE_HEADINGS),
        ];
    }

    /**
     * @param  list<string>  $row
     * @param  array{date: int, description: ?int, reference: ?int, payee: ?int, debit: ?int, credit: ?int, amount: ?int, balance: ?int}  $columns
     */
    private function amountFor(array $row, array $columns, int $lineNo): BigDecimal
    {
        if ($columns['amount'] !== null) {
            $raw = $this->value($row, $columns['amount']);

            if ($raw !== null && trim($raw) !== '') {
                return $this->decimal($raw, $lineNo);
            }
        }

        /*
         * Debit and credit columns: money in is positive, money out is
         * negative, whichever of the two the bank filled in. Both filled in
         * is a malformed row, and the subtraction below treats it as a net —
         * which is the only reading that keeps the running balance right.
         */
        $debit = $this->optionalAmount($row, $columns['debit'], $lineNo);
        $credit = $this->optionalAmount($row, $columns['credit'], $lineNo);

        return $credit->minus($debit);
    }

    /**
     * @param  list<string>  $row
     */
    private function optionalAmount(array $row, ?int $column, int $lineNo): BigDecimal
    {
        if ($column === null) {
            return BigDecimal::zero();
        }

        $raw = $this->value($row, $column);

        if ($raw === null || trim($raw) === '') {
            return BigDecimal::zero();
        }

        return $this->decimal($raw, $lineNo)->abs();
    }

    /**
     * A number as banks write it.
     *
     * Thousands separators, a currency symbol, a trailing CR or DR, and
     * accountants' parentheses for negatives all mean the same thing to a
     * person and none of them to `BigDecimal`.
     */
    private function decimal(string $raw, int $lineNo): BigDecimal
    {
        $value = trim($raw);
        $negative = false;

        if (preg_match('/^\((.*)\)$/', $value, $matches) === 1) {
            $negative = true;
            $value = $matches[1];
        }

        if (preg_match('/\b(DR|CR)\b/i', $value, $matches) === 1) {
            $negative = $negative || mb_strtoupper($matches[1]) === 'DR';
            $value = (string) preg_replace('/\b(DR|CR)\b/i', '', $value);
        }

        // Everything that is not a digit, a sign or a decimal point.
        $value = (string) preg_replace('/[^0-9.\-+]/', '', $value);

        if ($value === '' || $value === '-' || $value === '+') {
            throw StatementUnreadable::unreadableAmount($raw, $lineNo);
        }

        try {
            $amount = BigDecimal::of($value)->toScale(4, RoundingMode::HalfUp);
        } catch (MathException) {
            throw StatementUnreadable::unreadableAmount($raw, $lineNo);
        }

        return $negative ? $amount->negated() : $amount;
    }

    private function optionalDecimal(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            return (string) $this->decimal($raw, 0);
        } catch (StatementUnreadable) {
            // A balance we cannot read is not worth failing an import over:
            // it is displayed, never reconciled against.
            return null;
        }
    }

    /**
     * Day-first or month-first, decided across the whole file.
     *
     * @param  list<list<string>>  $rows
     */
    private function inferDayFirst(array $rows, int $dateColumn): bool
    {
        foreach ($rows as $row) {
            $raw = $this->value($row, $dateColumn);

            if ($raw === null) {
                continue;
            }

            if (preg_match('~^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$~', trim($raw), $m) !== 1) {
                continue;
            }

            if ((int) $m[1] > 12) {
                return true;
            }

            if ((int) $m[2] > 12) {
                return false;
            }
        }

        // Ambiguous from end to end. Day-first, and consistently so.
        return true;
    }

    private function parseDate(string $raw, bool $dayFirst, int $lineNo): Carbon
    {
        $value = trim($raw);

        $formats = $dayFirst
            ? ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'd-m-y', 'd M Y', 'd-M-Y', 'd M y', 'd-M-y', 'Y/m/d']
            : ['Y-m-d', 'm/d/Y', 'm-d-Y', 'm.d.Y', 'm/d/y', 'm-d-y', 'M d Y', 'M-d-Y', 'M d, Y', 'Y/m/d'];

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

        throw StatementUnreadable::unreadableDate($raw, $lineNo);
    }

    /**
     * @param  list<string>  $row
     */
    private function value(array $row, ?int $column): ?string
    {
        if ($column === null || ! array_key_exists($column, $row)) {
            return null;
        }

        $value = trim($row[$column]);

        return $value === '' ? null : $value;
    }

    private function trimToNull(?string $value, int $length): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $length);
    }

    /**
     * @param  list<string>  $row
     */
    private function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
