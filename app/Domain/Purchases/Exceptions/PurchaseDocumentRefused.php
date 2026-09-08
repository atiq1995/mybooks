<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Exceptions;

use RuntimeException;

/**
 * A purchase document could not do what was asked of it.
 *
 * Distinct from a validation failure: the input was well formed, but the
 * document's state or the organisation's setup makes the action wrong. These
 * messages are shown to a user, so they say what to do next.
 */
final class PurchaseDocumentRefused extends RuntimeException
{
    public static function notEditable(string $type, string $number, string $status): self
    {
        return new self(
            "{$type} {$number} is {$status} and can no longer be edited. ".
            'Raise a vendor credit against it, or void it and start again — an approved '.
            'document is a record, not a draft.'
        );
    }

    public static function alreadyIssued(string $type, string $number): self
    {
        return new self("{$type} {$number} has already been approved.");
    }

    public static function hasNoLines(string $type, string $number): self
    {
        return new self("{$type} {$number} has no lines, so there is nothing to approve.");
    }

    public static function nothingToIssue(string $type, string $number): self
    {
        return new self(
            "{$type} {$number} totals zero. A document with no value has no accounting ".
            'effect and cannot be approved.'
        );
    }

    public static function alreadyVoid(string $type, string $number): self
    {
        return new self("{$type} {$number} is already void.");
    }

    public static function cannotVoidUnissued(string $type, string $number): self
    {
        return new self(
            "{$type} {$number} was never approved, so there is nothing to void. ".
            'Delete the draft instead.'
        );
    }

    public static function hasPayments(string $type, string $number, string $paid): self
    {
        return new self(
            "{$type} {$number} has {$paid} paid against it. Reverse the payment first — ".
            'voiding it would leave money allocated to a document that no longer exists.'
        );
    }

    public static function contactUnusable(string $name): self
    {
        return new self(
            "{$name} is archived, so no new document can be raised for them. ".
            'Reactivate the contact first.'
        );
    }

    public static function notAVendor(string $name): self
    {
        return new self(
            "{$name} is not marked as a vendor, so they cannot be billed from. ".
            'Change the contact to a vendor, or to both if they are also a customer.'
        );
    }

    /**
     * The duplicate-bill guard.
     *
     * Paying the same bill twice is the most common and most expensive
     * mistake in accounts payable, and the vendor's own number is the only
     * reliable way to notice it — so this refusal names the document it
     * collides with rather than just saying no.
     */
    public static function duplicateVendorReference(
        string $reference,
        string $vendor,
        string $existingNumber,
    ): self {
        return new self(
            "{$vendor} already has a bill with their reference {$reference} — ".
            "{$existingNumber}. Entering it twice is how a bill gets paid twice. ".
            'If this really is a second bill with the same number, change the reference '.
            'to tell them apart.'
        );
    }

    public static function creditExceedsBill(string $number, string $available): self
    {
        return new self(
            "Only {$available} of {$number} remains to credit. A vendor credit cannot ".
            'exceed what is still owed, or the vendor would be in credit twice over.'
        );
    }

    public static function cannotCredit(string $number, string $status): self
    {
        return new self(
            "{$number} is {$status}, so it cannot be credited. Only an approved, ".
            'unvoided bill can be.'
        );
    }

    public static function allocationExceedsPayment(string $number, string $unallocated): self
    {
        return new self(
            "Payment {$number} has only {$unallocated} left to allocate. ".
            'Allocating more would apply money that never left the bank.'
        );
    }

    public static function allocationExceedsDocument(string $number, string $due): self
    {
        return new self(
            "{$number} has only {$due} outstanding. Allocating more would overpay it — ".
            'leave the remainder unallocated and it is held as an advance with the vendor.'
        );
    }

    public static function wrongCurrency(string $number, string $expected, string $actual): self
    {
        return new self(
            "{$number} is in {$expected}, but the payment is in {$actual}. ".
            'Settle a document in its own currency, or the amount cleared is ambiguous.'
        );
    }

    /**
     * A line pointing at the wrong kind of account.
     *
     * The purchase side has more room for this mistake than sales does: the
     * cost of a purchase legitimately lands in an expense account or an asset
     * account, so the check cannot simply be "expense". What it can refuse is
     * income, liability and equity, none of which a purchase ever debits.
     */
    public static function notACostAccount(string $account, string $type): self
    {
        return new self(
            "{$account} is an {$type} account, so a purchase line cannot be charged to ".
            'it. Charge the cost to an expense account, or to an asset account if what '.
            'was bought is still worth something.'
        );
    }
}
