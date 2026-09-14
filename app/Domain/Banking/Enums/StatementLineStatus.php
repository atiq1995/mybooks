<?php

declare(strict_types=1);

namespace App\Domain\Banking\Enums;

/**
 * Where an imported statement line has got to.
 *
 * Note what is absent: there is no "posted". A statement line never posts
 * anything — §8 — so its whole life is "we have not decided what this is",
 * "this is that entry over there", or "this is not ours".
 */
enum StatementLineStatus: string
{
    case Unmatched = 'unmatched';
    case Matched = 'matched';
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::Unmatched => 'Unmatched',
            self::Matched => 'Matched',
            self::Excluded => 'Excluded',
        };
    }

    /**
     * Whether a line in this state counts towards the cleared balance.
     *
     * Only a matched one does. An excluded line is explicitly not ours, and
     * an unmatched one is precisely what the reconciliation is still looking
     * for.
     */
    public function clears(): bool
    {
        return $this === self::Matched;
    }
}
