<?php

declare(strict_types=1);

namespace App\Domain\Reports\Data;

/**
 * One column of a report, and how to read it.
 *
 * `kind` decides alignment and formatting everywhere at once: money is right
 * aligned and tabular in the browser, right aligned with a number format in
 * the spreadsheet, and right aligned in the PDF. Deciding that per renderer
 * is how a column ends up left-aligned in one export and right in another.
 */
final readonly class ReportColumn
{
    public function __construct(
        public string $key,
        public string $label,
        /** 'text' | 'money' | 'percent' | 'number' */
        public string $kind = 'money',
    ) {}

    public static function text(string $key, string $label): self
    {
        return new self($key, $label, 'text');
    }

    public static function money(string $key, string $label): self
    {
        return new self($key, $label, 'money');
    }

    public static function percent(string $key, string $label): self
    {
        return new self($key, $label, 'percent');
    }

    public static function number(string $key, string $label): self
    {
        return new self($key, $label, 'number');
    }

    public function isNumeric(): bool
    {
        return $this->kind !== 'text';
    }

    /**
     * @return array{key: string, label: string, kind: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'kind' => $this->kind];
    }
}
