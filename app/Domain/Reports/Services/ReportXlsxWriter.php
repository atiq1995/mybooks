<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Reports\Data\ReportColumn;
use App\Domain\Reports\Data\ReportRow;
use App\Domain\Reports\Data\ReportTable;
use RuntimeException;
use ZipArchive;

/**
 * A report as a real .xlsx file, written here rather than by a library.
 *
 * An xlsx is a zip of a handful of XML parts, and what this module needs of
 * it is small and fixed: one sheet, inline strings, numbers as numbers, bold
 * headings, a thousands-separated money format and frozen headers. A
 * spreadsheet library would bring several megabytes and a large API surface
 * to produce exactly that, and would still need this much code to drive it.
 *
 * The part that actually matters is that **money is written as a NUMBER with
 * a display format**, not as a pre-formatted string. A figure that arrives in
 * a spreadsheet as text cannot be summed, and the first thing anybody does
 * with an exported report is sum a column.
 *
 * Values cross as decimal strings and are written into the XML verbatim —
 * `118000.0000` is valid in a `<v>` element — so nothing here parses a money
 * amount as a float on the way out.
 */
final readonly class ReportXlsxWriter
{
    /** Style indexes into the `cellXfs` list built in {@see styles()}. */
    private const STYLE_DEFAULT = 0;

    private const STYLE_BOLD = 1;

    private const STYLE_MONEY = 2;

    private const STYLE_MONEY_BOLD = 3;

    private const STYLE_TITLE = 4;

    public function write(ReportTable $table): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the spreadsheet.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the spreadsheet for writing.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('xl/workbook.xml', $this->workbook($table));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($table));

        $zip->close();

        $contents = file_get_contents($path);
        unlink($path);

        if ($contents === false) {
            throw new RuntimeException('The spreadsheet could not be read back after writing.');
        }

        return $contents;
    }

    private function sheet(ReportTable $table): string
    {
        $rows = [];
        $rowNumber = 1;

        $rows[] = $this->row($rowNumber++, [
            ['value' => $table->title, 'style' => self::STYLE_TITLE],
        ]);

        $rows[] = $this->row($rowNumber++, [
            ['value' => $table->period->label, 'style' => self::STYLE_DEFAULT],
            [
                'value' => $table->period->fromDate().' to '.$table->period->toDate(),
                'style' => self::STYLE_DEFAULT,
            ],
        ]);

        $rows[] = $this->row($rowNumber++, [
            ['value' => 'Amounts in '.$table->currency, 'style' => self::STYLE_DEFAULT],
        ]);

        if ($table->reconciliation !== null) {
            $rows[] = $this->row($rowNumber++, [
                [
                    'value' => $table->reconciles ? 'Reconciles' : 'DOES NOT RECONCILE',
                    'style' => self::STYLE_BOLD,
                ],
                ['value' => $table->reconciliation, 'style' => self::STYLE_DEFAULT],
            ]);
        }

        $rowNumber++;

        // The header row, and the one everything below is frozen beneath.
        $headerRow = $rowNumber;

        $rows[] = $this->row($rowNumber++, array_map(
            static fn (ReportColumn $column): array => [
                'value' => $column->label,
                'style' => self::STYLE_BOLD,
            ],
            $table->columns,
        ));

        foreach ($table->flatten() as $row) {
            if ($row->style === 'spacer') {
                $rowNumber++;

                continue;
            }

            $rows[] = $this->row($rowNumber++, $this->cells($table, $row));
        }

        $frozen = $headerRow;
        $body = implode('', $rows);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
            <sheetViews><sheetView workbookViewId="0" tabSelected="1">
            <pane ySplit="{$frozen}" topLeftCell="A{$this->nextRow($frozen)}" activePane="bottomLeft" state="frozen"/>
            </sheetView></sheetViews>
            <cols><col min="1" max="1" width="46" customWidth="1"/><col min="2" max="8" width="18" customWidth="1"/></cols>
            <sheetData>{$body}</sheetData>
            </worksheet>
            XML;
    }

    /**
     * @param  list<array{value: string|null, style: int, numeric?: bool}>  $cells
     */
    private function row(int $number, array $cells): string
    {
        $xml = '';

        foreach ($cells as $index => $cell) {
            $value = $cell['value'];

            if ($value === null || $value === '') {
                continue;
            }

            $reference = $this->columnLetter($index).$number;
            $style = $cell['style'];

            if (($cell['numeric'] ?? false) && is_numeric($value)) {
                $xml .= sprintf('<c r="%s" s="%d"><v>%s</v></c>', $reference, $style, $value);

                continue;
            }

            $xml .= sprintf(
                '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
                $reference,
                $style,
                htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            );
        }

        return sprintf('<row r="%d">%s</row>', $number, $xml);
    }

    /**
     * @return list<array{value: string|null, style: int, numeric?: bool}>
     */
    private function cells(ReportTable $table, ReportRow $row): array
    {
        $emphasised = in_array($row->style, ['total', 'subtotal', 'heading'], true);
        $cells = [];

        foreach ($table->columns as $index => $column) {
            if ($index === 0) {
                $label = $row->code === null || $row->style !== 'row'
                    ? $row->label
                    : $row->code.'  '.$row->label;

                // Indentation carries the structure a heading-and-rows layout
                // has in the browser but not in a grid of cells.
                $cells[] = [
                    'value' => str_repeat('    ', $row->depth).$label,
                    'style' => $emphasised ? self::STYLE_BOLD : self::STYLE_DEFAULT,
                ];

                continue;
            }

            $value = $row->values[$column->key] ?? null;

            $cells[] = [
                'value' => $value,
                'style' => $emphasised ? self::STYLE_MONEY_BOLD : self::STYLE_MONEY,
                'numeric' => $column->isNumeric(),
            ];
        }

        return $cells;
    }

    private function columnLetter(int $index): string
    {
        $letter = '';

        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $letter = chr(65 + $i % 26).$letter;
        }

        return $letter;
    }

    private function nextRow(int $row): int
    {
        return $row + 1;
    }

    private function contentTypes(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
            <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
            <Default Extension="xml" ContentType="application/xml"/>
            <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
            <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
            <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
            </Types>
            XML;
    }

    private function rootRelationships(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
            </Relationships>
            XML;
    }

    private function workbookRelationships(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
            </Relationships>
            XML;
    }

    private function workbook(ReportTable $table): string
    {
        /*
         * Sheet names are limited to 31 characters and may not contain
         * : \ / ? * [ ]. A report title is user-visible text, so it is
         * sanitised rather than trusted.
         */
        $name = (string) preg_replace('/[:\\\\\/?*\[\]]/', ' ', $table->title);
        $name = mb_substr(trim($name), 0, 31);
        $name = $name === '' ? 'Report' : $name;

        $escaped = htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
                      xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <sheets><sheet name="{$escaped}" sheetId="1" r:id="rId1"/></sheets>
            </workbook>
            XML;
    }

    /**
     * Five cell formats, in the order the STYLE_ constants name them.
     */
    private function styles(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
            <numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00;[Red]-#,##0.00"/></numFmts>
            <fonts count="3">
            <font><sz val="11"/><name val="Calibri"/></font>
            <font><b/><sz val="11"/><name val="Calibri"/></font>
            <font><b/><sz val="14"/><name val="Calibri"/></font>
            </fonts>
            <fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>
            <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
            <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
            <cellXfs count="5">
            <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
            <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>
            <xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
            <xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>
            <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
            </cellXfs>
            </styleSheet>
            XML;
    }
}
