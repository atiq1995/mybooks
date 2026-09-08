<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

/**
 * The four sales documents, and — the only thing that really distinguishes
 * them — whether they touch the ledger.
 *
 * An estimate and a sales order are commitments. They never post, however
 * they are edited, converted or approved, and a database constraint enforces
 * that independently of this enum.
 *
 * @see ACCOUNTING_RULES.md §6
 */
enum SalesDocumentType: string
{
    case Estimate = 'estimate';
    case SalesOrder = 'sales_order';
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return match ($this) {
            self::Estimate => 'Estimate',
            self::SalesOrder => 'Sales order',
            self::Invoice => 'Invoice',
            self::CreditNote => 'Credit note',
        };
    }

    public function plural(): string
    {
        return match ($this) {
            self::Estimate => 'Estimates',
            self::SalesOrder => 'Sales orders',
            self::Invoice => 'Invoices',
            self::CreditNote => 'Credit notes',
        };
    }

    /** Whether issuing this document posts a journal entry. */
    public function posts(): bool
    {
        return match ($this) {
            self::Invoice, self::CreditNote => true,
            self::Estimate, self::SalesOrder => false,
        };
    }

    /** The document_sequences key, so numbering is per type. */
    public function sequenceKey(): string
    {
        return match ($this) {
            self::Estimate => 'estimate',
            self::SalesOrder => 'sales_order',
            self::Invoice => 'invoice',
            self::CreditNote => 'credit_note',
        };
    }

    /** The `source_type` recorded on the journal entry it produces. */
    public function ledgerSource(): string
    {
        return $this->value;
    }

    /** Whether a due date is meaningful; a quote has an expiry instead. */
    public function hasDueDate(): bool
    {
        return $this === self::Invoice || $this === self::CreditNote;
    }

    public function urlSegment(): string
    {
        return match ($this) {
            self::Estimate => 'estimates',
            self::SalesOrder => 'sales-orders',
            self::Invoice => 'invoices',
            self::CreditNote => 'credit-notes',
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
