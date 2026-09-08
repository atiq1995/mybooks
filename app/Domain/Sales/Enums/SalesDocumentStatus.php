<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

/**
 * Where a sales document is in its life.
 *
 * The lifecycles differ by type, and §6 spells them out:
 *
 *   estimate     draft → sent → accepted | declined | expired
 *   sales order  draft → sent → closed
 *   invoice      draft → sent → partially_paid → paid, with overdue alongside
 *   credit note  draft → sent
 *
 * `Void` is reachable from any posted state and always posts a reversing
 * entry. Nothing is ever deleted.
 *
 * @see ACCOUNTING_RULES.md §6
 */
enum SalesDocumentStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';

    // Estimates
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';

    // Invoices
    case Open = 'open';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';

    // Sales orders
    case Closed = 'closed';

    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Expired => 'Expired',
            self::Open => 'Open',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Closed => 'Closed',
            self::Void => 'Void',
        };
    }

    /**
     * A draft has no accounting effect at all, so it is the only state in
     * which a document can still be edited.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Whether this state means the document has been issued. */
    public function isIssued(): bool
    {
        return ! in_array($this, [self::Draft, self::Void], strict: true);
    }

    /** Whether it still owes money. */
    public function isOutstanding(): bool
    {
        return in_array(
            $this,
            [self::Sent, self::Open, self::PartiallyPaid, self::Overdue],
            strict: true,
        );
    }

    public function isVoid(): bool
    {
        return $this === self::Void;
    }

    /** Badge tone on the list screens. */
    public function tone(): string
    {
        return match ($this) {
            self::Paid, self::Accepted => 'success',
            self::Overdue, self::Declined => 'danger',
            self::PartiallyPaid, self::Expired => 'warning',
            self::Sent, self::Open => 'info',
            self::Draft, self::Closed, self::Void => 'neutral',
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
