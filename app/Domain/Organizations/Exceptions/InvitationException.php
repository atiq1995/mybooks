<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Exceptions;

use RuntimeException;

/**
 * Something is wrong with an invitation.
 *
 * Messages here reach the person holding the link, so they say what to do
 * next rather than what went wrong internally — and they never confirm
 * whether a given email already has an account.
 */
final class InvitationException extends RuntimeException
{
    /**
     * Which form input this failure belongs against, so a controller can put
     * the message where the person will look for it rather than guessing.
     */
    public string $field = 'email';

    private static function forField(string $field, string $message): self
    {
        $exception = new self($message);
        $exception->field = $field;

        return $exception;
    }

    public static function alreadyAMember(string $organization): self
    {
        return new self("That person is already part of {$organization}.");
    }

    public static function cannotAssignRole(string $role): self
    {
        return self::forField(
            'role',
            "You cannot grant the {$role} role, because it is above your own.",
        );
    }

    public static function cannotInviteSelf(): self
    {
        return new self('You are already a member of this organisation.');
    }

    public static function notFound(): self
    {
        return new self('This invitation link is not valid. Ask for a new one.');
    }

    public static function expired(): self
    {
        return new self('This invitation has expired. Ask for a new one.');
    }

    public static function alreadyAccepted(): self
    {
        return new self('This invitation has already been accepted. Try signing in.');
    }

    public static function wrongAccount(string $invitedEmail): self
    {
        return new self(
            "This invitation was sent to {$invitedEmail}. Sign out and sign in as that person to accept it."
        );
    }

    public static function lastOwner(): self
    {
        return new self(
            'An organisation must keep at least one owner. Make someone else an owner first.'
        );
    }
}
