<?php

declare(strict_types=1);

namespace App\Domain\Banking\Enums;

/**
 * Two states, and no way back from the second.
 *
 * There is deliberately no "reopened". A completed reconciliation is a
 * statement about the books at a date, and reopening it would quietly
 * withdraw that statement. Corrections are made the way every other
 * correction in this system is made: forward, in a later period.
 */
enum ReconciliationStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'In progress',
            self::Completed => 'Completed',
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Completed;
    }
}
