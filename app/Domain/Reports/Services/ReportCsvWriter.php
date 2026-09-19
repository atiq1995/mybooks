<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Reports\Data\ReportColumn;
use App\Domain\Reports\Data\ReportRow;
use App\Domain\Reports\Data\ReportTable;

/**
 * A report as CSV.
 *
 * Numbers go out UNFORMATTED — `118000.0000`, not `PKR 118,000.00`. A CSV is
 * read by a machine or pasted into a spreadsheet, and a thousands separator
 * turns a number into text the moment it arrives. The currency is stated once
 * in the header block instead, where it informs without corrupting anything.
 *
 * The header block also carries the period and the reconciliation statement,
 * because a figure detached from the span it covers is not information.
 */
final readonly class ReportCsvWriter
{
    public function write(ReportTable $table): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Could not open a buffer to write the CSV into.');
        }

        $this->put($handle, [$table->title]);
        $this->put($handle, [
            $table->period->label,
            $table->period->fromDate().' to '.$table->period->toDate(),
        ]);
        $this->put($handle, ['Currency', $table->currency]);

        if ($table->reconciliation !== null) {
            $this->put($handle, [
                $table->reconciles ? 'Reconciles' : 'DOES NOT RECONCILE',
                $table->reconciliation,
            ]);
        }

        $this->put($handle, []);

        $this->put($handle, array_map(
            static fn (ReportColumn $column): string => $column->label,
            $table->columns,
        ));

        foreach ($table->flatten() as $row) {
            if ($row->style === 'spacer') {
                $this->put($handle, []);

                continue;
            }

            $this->put($handle, $this->cells($table, $row));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $fields
     */
    private function put($handle, array $fields): void
    {
        fputcsv($handle, $fields, ',', '"', '\\');
    }

    /**
     * @return list<string>
     */
    private function cells(ReportTable $table, ReportRow $row): array
    {
        $cells = [];

        foreach ($table->columns as $index => $column) {
            if ($index === 0) {
                // The account code belongs beside the name, not in a column
                // of its own: a spreadsheet sorted by name is useless, and a
                // reader scanning for 4010 finds it either way.
                $cells[] = $row->code === null || $row->style !== 'row'
                    ? $row->label
                    : $row->code.' '.$row->label;

                continue;
            }

            $cells[] = $row->values[$column->key] ?? '';
        }

        return $cells;
    }
}
