<?php

declare(strict_types=1);

namespace App\Domain\Reports\Data;

/**
 * One line of a report.
 *
 * `drill` is the part that matters. Phase 7's exit criterion is that every
 * figure drills through to the journal lines that produced it, and a row
 * carries the exact filter that reproduces it: an account, and the span the
 * figure was summed over. Not a guess assembled by the screen from whatever
 * happens to be in the URL — the same bounds the total itself used.
 *
 * A row with no `drill` is one no single query can reproduce: a subtotal, a
 * heading, or a derived figure like gross profit. Those say so by leaving it
 * null rather than by linking somewhere approximate.
 */
final readonly class ReportRow
{
    /**
     * @param  array<string, string|null>  $values  keyed by column
     * @param  array{account_id: string, from: ?string, to: string}|null  $drill
     */
    public function __construct(
        public string $label,
        public array $values = [],
        public int $depth = 0,
        public string $style = 'row',
        public ?array $drill = null,
        public ?string $code = null,
    ) {}

    /**
     * @param  array<string, string|null>  $values
     * @param  array{account_id: string, from: ?string, to: string}|null  $drill
     */
    public static function account(
        string $code,
        string $name,
        array $values,
        ?array $drill = null,
        int $depth = 1,
    ): self {
        return new self(
            label: $name,
            values: $values,
            depth: $depth,
            style: 'row',
            drill: $drill,
            code: $code,
        );
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public static function subtotal(string $label, array $values, int $depth = 0): self
    {
        return new self($label, $values, $depth, 'subtotal');
    }

    /**
     * A figure the reader is meant to stop at — gross profit, net profit, the
     * balance sheet's two sides.
     *
     * @param  array<string, string|null>  $values
     */
    public static function total(string $label, array $values, int $depth = 0): self
    {
        return new self($label, $values, $depth, 'total');
    }

    public static function heading(string $label): self
    {
        return new self($label, [], 0, 'heading');
    }

    public static function spacer(): self
    {
        return new self('', [], 0, 'spacer');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'code' => $this->code,
            'values' => $this->values,
            'depth' => $this->depth,
            'style' => $this->style,
            'drill' => $this->drill,
        ];
    }
}
