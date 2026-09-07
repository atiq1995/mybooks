<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * Whether a fiscal period will accept a posting.
 *
 * @see ACCOUNTING_RULES.md §7
 */
enum PeriodStatus: string
{
    /** Anyone holding accounting.post may post. */
    case Open = 'open';

    /** Only with accounting.post_to_closed_period, and audited on every use. */
    case Closed = 'closed';

    /** Never, by anyone. Set after filing, and cannot be reopened. */
    case Locked = 'locked';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Locked => 'Locked',
        };
    }

    public function acceptsPostings(): bool
    {
        return $this === self::Open;
    }

    /** Whether an override permission can still get a posting in. */
    public function acceptsOverride(): bool
    {
        return $this === self::Closed;
    }

    public function canReopen(): bool
    {
        return $this === self::Closed;
    }
}
