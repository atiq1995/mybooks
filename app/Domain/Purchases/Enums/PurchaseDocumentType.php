<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Enums;

/**
 * The three purchase documents, and the thing that really separates them:
 * whether they touch the ledger.
 *
 * A purchase order is a commitment. It never posts, however it is edited,
 * sent or converted, and a database constraint enforces that independently of
 * this enum.
 *
 * A bill posts on APPROVAL rather than on entry — §6 — which is the one place
 * the purchase side genuinely differs from sales. An invoice is issued by the
 * person who wrote it; a bill arrives from outside and somebody has to agree
 * it is owed before it becomes a liability.
 *
 * @see ACCOUNTING_RULES.md §6
 */
enum PurchaseDocumentType: string
{
    case PurchaseOrder = 'purchase_order';
    case Bill = 'bill';
    case VendorCredit = 'vendor_credit';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseOrder => 'Purchase order',
            self::Bill => 'Bill',
            self::VendorCredit => 'Vendor credit',
        };
    }

    public function plural(): string
    {
        return match ($this) {
            self::PurchaseOrder => 'Purchase orders',
            self::Bill => 'Bills',
            self::VendorCredit => 'Vendor credits',
        };
    }

    /** Whether approving this document posts a journal entry. */
    public function posts(): bool
    {
        return match ($this) {
            self::Bill, self::VendorCredit => true,
            self::PurchaseOrder => false,
        };
    }

    /**
     * What the button says.
     *
     * A purchase order is sent; a bill is approved. The word matters: it is
     * the difference between telling a vendor what we want and accepting that
     * we owe them money.
     */
    public function issueVerb(): string
    {
        return match ($this) {
            self::PurchaseOrder => 'Send',
            self::Bill => 'Approve',
            self::VendorCredit => 'Issue',
        };
    }

    /** The document_sequences key, so numbering is per type. */
    public function sequenceKey(): string
    {
        return $this->value;
    }

    /** The `source_type` recorded on the journal entry it produces. */
    public function ledgerSource(): string
    {
        return $this->value;
    }

    /** Whether a due date is meaningful. */
    public function hasDueDate(): bool
    {
        return $this === self::Bill;
    }

    public function urlSegment(): string
    {
        return match ($this) {
            self::PurchaseOrder => 'orders',
            self::Bill => 'bills',
            self::VendorCredit => 'vendor-credits',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
