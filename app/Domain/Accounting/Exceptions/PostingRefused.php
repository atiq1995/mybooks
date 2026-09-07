<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use RuntimeException;

/**
 * The ledger declined to accept an otherwise well-formed entry.
 *
 * Distinct from {@see UnbalancedJournal}: the arithmetic is fine, but
 * something about the context is not — a closed period, an inactive account,
 * a source that has already posted. Several of these ARE worth showing to a
 * user, so the messages say what to do next.
 */
final class PostingRefused extends RuntimeException
{
    public static function periodClosed(string $period, string $date): self
    {
        return new self(
            "The period {$period} is closed, so nothing can be posted on {$date}. ".
            'An administrator can reopen it, or post into an open period instead.'
        );
    }

    public static function periodLocked(string $period): self
    {
        return new self(
            "The period {$period} is locked and cannot be reopened. It was locked because ".
            'the figures have been filed. Post a correcting entry in an open period instead.'
        );
    }

    public static function noPeriodForDate(string $date): self
    {
        return new self(
            "No fiscal period covers {$date}. Create the financial year that contains it ".
            'before posting.'
        );
    }

    public static function accountNotFound(string $accountId): self
    {
        return new self("Account {$accountId} does not exist in this organisation.");
    }

    public static function accountInactive(string $code, string $name): self
    {
        return new self(
            "Account {$code} {$name} is archived, so nothing can be posted to it. ".
            'Reactivate it, or choose another account.'
        );
    }

    public static function accountIsHeader(string $code, string $name): self
    {
        return new self(
            "Account {$code} {$name} is a heading that groups other accounts. Post to one of ".
            'its children instead — posting to a heading makes its subtotal meaningless.'
        );
    }

    public static function alreadyPosted(string $sourceType, string $sourceId, string $purpose): self
    {
        return new self(
            "This {$sourceType} has already posted its {$purpose} entry. Posting again would ".
            "double it. (source {$sourceId})"
        );
    }

    public static function currencyMismatch(string $expected, string $actual): self
    {
        return new self(
            "This organisation keeps its books in {$expected}, but the entry declares a base ".
            "currency of {$actual}. The base currency is fixed once anything is posted."
        );
    }

    public static function alreadyReversed(string $entryNo): self
    {
        return new self("Entry {$entryNo} has already been reversed.");
    }

    public static function cannotReverseAReversal(string $entryNo): self
    {
        return new self(
            "Entry {$entryNo} is itself a reversal. Reversing it would restore the original ".
            'error. Post a fresh correcting entry instead.'
        );
    }
}
