<?php

declare(strict_types=1);

namespace App\Domain\Reports\Data;

/**
 * A finished report, in the one shape everything downstream understands.
 *
 * The screen renders it, the CSV writer flattens it, the spreadsheet writer
 * styles it and the PDF renderer paginates it — all from this. Four
 * renderers each reading a report's own private structure is four places for
 * an exported figure to differ from the one on screen, and a difference
 * between what somebody saw and what they sent their accountant is the worst
 * kind of bug this module can have.
 *
 * `reconciles` is not decoration either. Every statement here can be checked
 * against the ledger, and the answer travels with the report so a reader
 * never has to take it on trust.
 */
final readonly class ReportTable
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<ReportSection>  $sections
     * @param  list<ReportRow>  $footer  grand totals, below every section
     * @param  array<string, string>  $notes  short statements shown under the
     *                                        table, keyed for stable ordering
     */
    public function __construct(
        public string $title,
        public string $currency,
        public ReportPeriod $period,
        public array $columns,
        public array $sections,
        public array $footer = [],
        public ?ReportPeriod $comparison = null,
        public ?string $subtitle = null,
        public bool $reconciles = true,
        public ?string $reconciliation = null,
        public array $notes = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'currency' => $this->currency,
            'period' => $this->period->toArray(),
            'comparison' => $this->comparison?->toArray(),
            'columns' => array_map(
                static fn (ReportColumn $column): array => $column->toArray(),
                $this->columns,
            ),
            'sections' => array_map(
                static fn (ReportSection $section): array => $section->toArray(),
                $this->sections,
            ),
            'footer' => array_map(
                static fn (ReportRow $row): array => $row->toArray(),
                $this->footer,
            ),
            'reconciles' => $this->reconciles,
            'reconciliation' => $this->reconciliation,
            'notes' => $this->notes,
        ];
    }

    /**
     * Every row in reading order, section headings included.
     *
     * What the exporters iterate. Flattening here rather than in each writer
     * is why a CSV and a PDF of the same report cannot fall out of step.
     *
     * @return list<ReportRow>
     */
    public function flatten(): array
    {
        $rows = [];

        foreach ($this->sections as $section) {
            if ($section->label !== null) {
                $rows[] = ReportRow::heading($section->label);
            }

            foreach ($section->rows as $row) {
                $rows[] = $row;
            }

            if ($section->total !== null) {
                $rows[] = $section->total;
            }
        }

        foreach ($this->footer as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** A filename stem, safe on every filesystem. */
    public function slug(): string
    {
        $slug = mb_strtolower(trim($this->title));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-').'-'.$this->period->toDate();
    }
}
