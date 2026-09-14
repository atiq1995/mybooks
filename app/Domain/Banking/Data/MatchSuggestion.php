<?php

declare(strict_types=1);

namespace App\Domain\Banking\Data;

use Illuminate\Support\Carbon;

/**
 * A candidate: "this statement line might be that journal line."
 *
 * A suggestion and nothing more. It carries its own reasons in plain words
 * precisely because the person confirming it is the control — a score with no
 * explanation invites clicking through a list, which is the failure mode this
 * whole design exists to avoid.
 */
final readonly class MatchSuggestion
{
    /**
     * @param  int  $confidence  0–100
     * @param  list<string>  $reasons  why this was suggested, for a human
     */
    public function __construct(
        public string $journalLineId,
        public string $journalEntryId,
        public string $entryNo,
        public Carbon $entryDate,
        public string $amount,
        public ?string $memo,
        public string $sourceType,
        public ?string $contactName,
        public int $confidence,
        public array $reasons,
    ) {}

    /**
     * Whether this is close enough to show first.
     *
     * There is no threshold above which anything happens by itself. This only
     * decides ordering and emphasis on screen.
     */
    public function isStrong(): bool
    {
        return $this->confidence >= 80;
    }
}
