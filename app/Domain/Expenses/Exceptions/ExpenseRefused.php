<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Exceptions;

use RuntimeException;

/**
 * An expense could not do what was asked of it.
 *
 * Distinct from a validation failure: the input was well formed, but the
 * expense's state or the organisation's setup makes the action wrong. These
 * messages are shown to a user, so they say what to do next.
 */
final class ExpenseRefused extends RuntimeException
{
    public static function notEditable(string $number, string $status): self
    {
        return new self(
            "Expense {$number} is {$status} and can no longer be edited. An approved ".
            'expense is corrected by voiding it and recording it again, which leaves '.
            'both on the record.'
        );
    }

    public static function hasNoLines(string $number): self
    {
        return new self("Expense {$number} has no lines, so there is nothing to submit.");
    }

    public static function nothingToApprove(string $number): self
    {
        return new self(
            "Expense {$number} totals zero. An expense with no value has no accounting ".
            'effect and cannot be approved.'
        );
    }

    public static function notSubmitted(string $number, string $status): self
    {
        return new self(
            "Expense {$number} is {$status}, so there is nothing to approve. It has to be ".
            'submitted first — which is the step that says somebody is claiming it.'
        );
    }

    public static function alreadySubmitted(string $number): self
    {
        return new self("Expense {$number} has already been submitted.");
    }

    public static function alreadyApproved(string $number): self
    {
        return new self("Expense {$number} has already been approved.");
    }

    public static function alreadyVoid(string $number): self
    {
        return new self("Expense {$number} is already void.");
    }

    public static function cannotVoidUnapproved(string $number): self
    {
        return new self(
            "Expense {$number} was never approved, so there is nothing to void. ".
            'Delete it instead.'
        );
    }

    /**
     * The self-approval guard.
     *
     * The one rule that makes an approval step mean anything. Without it the
     * workflow is two clicks by the same person, which is not a control — it
     * is a formality that makes the books look reviewed when they are not.
     */
    public static function cannotApproveOwn(string $number): self
    {
        return new self(
            "You submitted expense {$number}, so somebody else has to approve it. An ".
            'approval by the person claiming the money is not a review.'
        );
    }

    public static function receiptRequired(string $number): self
    {
        return new self(
            "Expense {$number} has no receipt attached. This organisation requires one ".
            'before an expense can be submitted.'
        );
    }

    public static function needsPaymentAccount(): self
    {
        return new self(
            'Say which account the money came out of. A company-paid expense has to '.
            'name one, because that is the account being credited.'
        );
    }

    public static function needsPayee(): self
    {
        return new self(
            'Say who is being reimbursed. A reimbursable expense creates a liability to '.
            'a person, and a liability to nobody in particular cannot be settled.'
        );
    }

    public static function notACostAccount(string $account, string $type): self
    {
        return new self(
            "{$account} is an {$type} account, so an expense line cannot be charged to ".
            'it. Charge it to an expense account, or to an asset account if what was '.
            'bought is still worth something.'
        );
    }

    public static function noMileageRate(string $unit): self
    {
        return new self(
            "No mileage rate is set for {$unit}. Add one under settings before claiming ".
            'mileage, so the rate on the claim is the one that was in force.'
        );
    }

    public static function alreadyBilled(string $number, string $invoice): self
    {
        return new self(
            "Expense {$number} has already been rebilled on {$invoice}. Billing it twice ".
            'would charge the customer twice for the same cost.'
        );
    }

    public static function notBillable(string $number): self
    {
        return new self(
            "Expense {$number} is not marked as billable to a customer, so there is ".
            'nothing to rebill.'
        );
    }
}
