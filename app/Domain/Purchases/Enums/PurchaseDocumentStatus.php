<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Enums;

/**
 * Where a purchase document is in its life.
 *
 * §6 gives the bill's lifecycle explicitly:
 *
 *   purchase order  draft → sent → closed
 *   bill            draft → open → partially_paid → paid, with overdue alongside
 *   vendor credit   draft → open
 *
 * Note that a bill goes to `open`, not `sent`. Nothing is sent — the bill
 * came from the vendor. `open` means approved and owed, which is exactly the
 * state that makes it a liability.
 *
 * `Void` is reachable from any posted state and always posts a reversing
 * entry. Nothing is ever deleted.
 *
 * @see ACCOUNTING_RULES.md §6
 */
enum PurchaseDocumentStatus: string
{
    case Draft = 'draft';

    // Purchase orders
    case Sent = 'sent';
    case Closed = 'closed';

    // Bills and vendor credits
    case Open = 'open';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';

    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Closed => 'Closed',
            self::Open => 'Open',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Void => 'Void',
        };
    }

    /**
     * A draft has no accounting effect, so it is the only state in which a
     * document can still be edited.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Whether this state means the document has been sent or approved. */
    public function isIssued(): bool
    {
        return ! in_array($this, [self::Draft, self::Void], strict: true);
    }

    /** Whether it still owes money. */
    public function isOutstanding(): bool
    {
        return in_array(
            $this,
            [self::Open, self::PartiallyPaid, self::Overdue],
            strict: true,
        );
    }

    public function isVoid(): bool
    {
        return $this === self::Void;
    }

    /**
     * Badge tone on the list screens.
     *
     * `Paid` is neutral rather than green on this side, on purpose. Money
     * leaving is not an achievement — the green on the sales list means "we
     * were paid", and using it here would tell the reader the opposite of
     * what they read a moment ago on the other screen.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Overdue => 'danger',
            self::PartiallyPaid => 'warning',
            self::Open, self::Sent => 'info',
            self::Draft, self::Paid, self::Closed, self::Void => 'neutral',
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
