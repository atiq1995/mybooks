<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Enums;

/**
 * State of a user's membership in an organisation.
 *
 * `Suspended` exists so access can be removed without deleting the
 * membership: the audit trail must remain attributable to a real person in a
 * real organisation long after they stop working there.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Invited => 'Invitation pending',
            self::Suspended => 'Suspended',
        };
    }

    /**
     * Whether this status permits signing in to the organisation at all.
     * An invitation must be accepted first; a suspension blocks entry.
     */
    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }
}
