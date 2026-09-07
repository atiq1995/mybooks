<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * A journal entry is posted, or it has been reversed.
 *
 * There is deliberately no draft and no deleted state. An entry exists in the
 * ledger or it does not exist at all — a DOCUMENT may be a draft, but the
 * journal it produces comes into being only when it posts.
 */
enum EntryStatus: string
{
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
        };
    }
}
