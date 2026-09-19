<?php

declare(strict_types=1);

namespace App\Domain\Reports\Data;

/**
 * A named block of rows with its own total.
 *
 * Sections are how a statement stays readable at the length a real chart of
 * accounts produces. A section with no label is a run of rows that belongs to
 * whatever came before it — used where a statement wants a total without a
 * second heading above it.
 */
final readonly class ReportSection
{
    /**
     * @param  list<ReportRow>  $rows
     */
    public function __construct(
        public ?string $label,
        public array $rows,
        public ?ReportRow $total = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'rows' => array_map(
                static fn (ReportRow $row): array => $row->toArray(),
                $this->rows,
            ),
            'total' => $this->total?->toArray(),
        ];
    }
}
