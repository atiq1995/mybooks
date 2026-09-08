<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Enums;

/**
 * Where an expense is in its life.
 *
 *   draft → submitted → approved
 *                    ↘ rejected → (edited, submitted again)
 *                                  approved → void (reverses)
 *
 * The approval step is the point of this lifecycle, and the reason expenses
 * have one where invoices do not: the person who spent the money is usually
 * the person recording it, so nothing should reach the ledger on their word
 * alone.
 *
 * A rejected expense goes back to being editable. That is deliberate — the
 * common rejection is "wrong category" or "no receipt", both of which are
 * fixed by editing rather than by starting again and losing the history of
 * having asked.
 *
 * @see ACCOUNTING_RULES.md §6
 */
enum ExpenseStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Void => 'Void',
        };
    }

    /**
     * A draft and a rejected expense are both editable.
     *
     * Nothing has posted in either state, and a rejection is a request to
     * change something rather than a final answer.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }

    /** Whether this state means it reached the ledger. */
    public function isPosted(): bool
    {
        return $this === self::Approved;
    }

    public function isSubmitted(): bool
    {
        return $this === self::Submitted;
    }

    public function isVoid(): bool
    {
        return $this === self::Void;
    }

    /** Whether it can still be withdrawn or deleted outright. */
    public function isDeletable(): bool
    {
        return $this !== self::Approved && $this !== self::Void;
    }

    /**
     * Badge tone on the list screens.
     *
     * `Submitted` is a warning rather than neutral or informational: it is
     * the state that needs somebody to do something, and it is the only one
     * on this screen that does.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Submitted => 'warning',
            self::Draft, self::Void => 'neutral',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
