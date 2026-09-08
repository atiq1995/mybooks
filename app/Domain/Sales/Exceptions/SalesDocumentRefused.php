<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exceptions;

use RuntimeException;

/**
 * A sales document could not do what was asked of it.
 *
 * Distinct from a validation failure: the input was well formed, but the
 * document's state or the organisation's setup makes the action wrong. Most
 * of these messages ARE shown to a user, so they say what to do next.
 */
final class SalesDocumentRefused extends RuntimeException
{
    public static function notEditable(string $type, string $number, string $status): self
    {
        return new self(
            "{$type} {$number} is {$status} and can no longer be edited. ".
            'Issue a credit note against it, or void it and start again — an issued '.
            'document is a record, not a draft.'
        );
    }

    public static function alreadyIssued(string $type, string $number): self
    {
        return new self("{$type} {$number} has already been issued.");
    }

    public static function hasNoLines(string $type, string $number): self
    {
        return new self("{$type} {$number} has no lines, so there is nothing to issue.");
    }

    public static function nothingToIssue(string $type, string $number): self
    {
        return new self(
            "{$type} {$number} totals zero. A document with no value has no accounting ".
            'effect and cannot be issued.'
        );
    }

    public static function alreadyVoid(string $type, string $number): self
    {
        return new self("{$type} {$number} is already void.");
    }

    public static function cannotVoidUnissued(string $type, string $number): self
    {
        return new self(
            "{$type} {$number} was never issued, so there is nothing to void. ".
            'Delete the draft instead.'
        );
    }

    public static function hasPayments(string $type, string $number, string $paid): self
    {
        return new self(
            "{$type} {$number} has {$paid} applied to it. Remove or refund the payments ".
            'first — voiding it would leave money allocated to a document that no longer '.
            'exists.'
        );
    }

    public static function contactUnusable(string $name): self
    {
        return new self(
            "{$name} is archived, so no new document can be raised for them. ".
            'Reactivate the contact first.'
        );
    }

    public static function notACustomer(string $name): self
    {
        return new self("{$name} is not marked as a customer, so they cannot be invoiced.");
    }

    public static function creditExceedsInvoice(string $number, string $available): self
    {
        return new self(
            "Only {$available} of {$number} remains to credit. A credit note cannot ".
            'exceed what is still owed, or the customer would end up in credit twice over.'
        );
    }

    public static function cannotCredit(string $number, string $status): self
    {
        return new self(
            "{$number} is {$status}, so it cannot be credited. Only an issued, unvoided ".
            'invoice can be.'
        );
    }

    public static function allocationExceedsPayment(string $number, string $unallocated): self
    {
        return new self(
            "Payment {$number} has only {$unallocated} left to allocate. ".
            'Allocating more would apply money that was never received.'
        );
    }

    public static function allocationExceedsDocument(string $number, string $due): self
    {
        return new self(
            "{$number} has only {$due} outstanding. Allocating more would overpay it — ".
            'leave the remainder unallocated and it is held as an advance.'
        );
    }

    public static function wrongCurrency(string $number, string $expected, string $actual): self
    {
        return new self(
            "{$number} is in {$expected}, but the payment is in {$actual}. ".
            'Settle a document in its own currency, or the amount cleared is ambiguous.'
        );
    }

    public static function noTaxesConfigured(): self
    {
        return new self(
            'This organisation has no tax rates set up yet. Add at least one before '.
            'invoicing — even a zero-rated one, so the return has something to report.'
        );
    }
}
