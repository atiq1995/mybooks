<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exceptions;

use RuntimeException;

/**
 * Banking refused to do something it was asked to do.
 *
 * The input was well formed; the state of the books makes the action wrong.
 * Every message here reaches a person, so each says what to do instead.
 */
final class BankingRefused extends RuntimeException
{
    public static function accountTypeMismatch(string $kind, string $expected, string $found): self
    {
        return new self(
            "A {$kind} has to be attached to a {$expected} account, and the one chosen is ".
            "{$found}. A credit card is money owed rather than money held, so it belongs on ".
            'the liability side of the chart — otherwise every balance sheet it appears on is wrong.'
        );
    }

    public static function accountNotPostable(string $name): self
    {
        return new self(
            "{$name} is a heading rather than an account that can hold a balance. ".
            'Choose the account itself.'
        );
    }

    public static function accountAlreadyAttached(string $name): self
    {
        return new self(
            "{$name} already belongs to another bank account. One ledger account is one ".
            'bank account, or there would be two answers to what its balance means.'
        );
    }

    public static function statementsNotSupported(string $kind): self
    {
        return new self(
            "A {$kind} has no statement to import. Reconciling it against a file somebody ".
            'typed themselves would prove nothing.'
        );
    }

    public static function lineAlreadySettled(string $description): self
    {
        return new self(
            "\"{$description}\" is already matched or excluded. Undo that first if it was wrong."
        );
    }

    public static function lineLocked(): self
    {
        return new self(
            'This line belongs to a completed reconciliation and cannot be changed. '.
            'Correct it by reversing in the ledger and reconciling the reversal in a later period.'
        );
    }

    public static function journalLineNotOnAccount(): self
    {
        return new self(
            'That entry is not on this bank account, so it cannot be what this statement '.
            'line refers to.'
        );
    }

    public static function journalLineAlreadyMatched(string $entryNo): self
    {
        return new self(
            "Entry {$entryNo} has already been matched to another statement line. One ledger ".
            'line clears once — matching it twice is how a reconciliation reaches zero while '.
            'being wrong.'
        );
    }

    public static function matchWrongDirection(): self
    {
        return new self(
            'That entry moves money the other way. A payment out cannot be what a deposit '.
            'refers to.'
        );
    }

    public static function matchOverAllocated(string $remaining): self
    {
        return new self(
            "This statement line has only {$remaining} left to match. Matching more than the ".
            'line is worth would put the reconciliation out by the difference.'
        );
    }

    public static function reconciliationInProgress(string $number): self
    {
        return new self(
            "Reconciliation {$number} is still open on this account. Finish or abandon it ".
            'before starting another — two at once would each see the other\'s matches.'
        );
    }

    public static function periodOverlapsCompleted(string $date): self
    {
        return new self(
            "This account is already reconciled up to {$date}. A new reconciliation has to ".
            'start after that, or the same transactions would be counted twice.'
        );
    }

    public static function notReconciled(string $difference): self
    {
        return new self(
            "This reconciliation is out by {$difference}. A difference is unfinished work, not ".
            'a rounding to accept: something on the statement is not in the books, something in '.
            'the books never reached the bank, or a match is wrong.'
        );
    }

    public static function alreadyCompleted(string $number): self
    {
        return new self(
            "Reconciliation {$number} is completed. It is a record of what the books said at a ".
            'date, and reopening it would withdraw that statement silently.'
        );
    }

    public static function transferToSameAccount(): self
    {
        return new self(
            'A transfer needs two different accounts. Moving money to where it already is '.
            'posts a debit and a credit to the same account and shows up nowhere.'
        );
    }

    public static function transferAccountUnusable(string $name): self
    {
        return new self("{$name} is archived or inactive, so money cannot be moved through it.");
    }

    public static function transferAlreadyVoided(string $number): self
    {
        return new self("Transfer {$number} has already been voided.");
    }

    public static function transferReconciled(string $number): self
    {
        return new self(
            "Transfer {$number} has been reconciled against a bank statement and cannot be ".
            'voided. Reverse it with a later entry instead, so both periods still add up.'
        );
    }
}
