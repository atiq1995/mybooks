<?php

declare(strict_types=1);

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Expenses\Data\ExpensePosting;
use Illuminate\Support\Carbon;

/*
|---------------------------------------------------------------------------
| The expense posting rule
|---------------------------------------------------------------------------
|
| §4.8, and the two ways it can be wrong while balancing perfectly:
| capitalising tax that could have been reclaimed, and reclaiming tax that
| could not. Both are asserted against the alternative rather than only in
| the affirmative.
|
| Pure: figures in, a JournalDraft out. No database, no tenant.
|
| Constants are prefixed, because a top-level `const` in a Pest file is
| global to the PHP process.
|
| @see ACCOUNTING_RULES.md §4.6, §4.8, §10
*/

const EXP_BANK = 'account-bank';
const EXP_REIMBURSE = 'account-employee-reimbursements';
const EXP_TRAVEL = 'account-travel';
const EXP_MEALS = 'account-entertainment';
const EXP_EQUIPMENT = 'account-equipment';
const EXP_GST_INPUT = 'account-gst-input';

/**
 * The journal lines, keyed by account, as [debit, credit] decimal strings.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function expenseLinesByAccount(JournalDraft $draft): array
{
    $lines = [];

    foreach ($draft->lines as $line) {
        $lines[$line->accountId] = [
            (string) $line->debitValue(),
            (string) $line->creditValue(),
        ];
    }

    return $lines;
}

/**
 * @param  list<array{account_id: string, amount: string}>  $costLines
 * @param  list<array{account_id: string, amount: string}>  $claimableTaxes
 */
function expensePosting(
    array $costLines,
    array $claimableTaxes = [],
    string $creditAccount = EXP_BANK,
    ?string $payee = null,
): JournalDraft {
    return (new ExpensePosting(
        creditAccountId: $creditAccount,
        costLines: $costLines,
        claimableTaxes: $claimableTaxes,
        currency: 'PKR',
        baseCurrency: 'PKR',
        exchangeRate: '1',
        date: Carbon::parse('2026-09-15'),
        expenseId: '01926f00-0000-7000-8000-0000000exp1',
        expenseNumber: 'EXP-000001',
        contactId: null,
        payee: $payee,
    ))->toDraft();
}

describe('§4.8 — an expense paid directly', function (): void {
    it('debits the expense and the input tax, and credits the bank', function (): void {
        // 10,000 of travel at 18% GST, all of it reclaimable.
        $lines = expenseLinesByAccount(expensePosting(
            costLines: [['account_id' => EXP_TRAVEL, 'amount' => '10000.0000']],
            claimableTaxes: [['account_id' => EXP_GST_INPUT, 'amount' => '1800.0000']],
        ));

        expect($lines[EXP_TRAVEL][0])->toBeDecimal('10000.0000')
            ->and($lines[EXP_GST_INPUT][0])->toBeDecimal('1800.0000')
            // The gross that actually left the account.
            ->and($lines[EXP_BANK][1])->toBeDecimal('11800.0000');
    });

    it('balances', function (): void {
        $draft = expensePosting(
            costLines: [
                ['account_id' => EXP_TRAVEL, 'amount' => '4321.5600'],
                ['account_id' => EXP_MEALS, 'amount' => '999.9900'],
            ],
            claimableTaxes: [['account_id' => EXP_GST_INPUT, 'amount' => '777.8808']],
        );

        expect((string) $draft->totalDebit())->toBeDecimal((string) $draft->totalCredit());
    });

    it('credits the person instead when the expense is reimbursable', function (): void {
        /*
         * The one fact that separates the two halves of §4.8. Everything
         * above the line is identical; only the credit moves — and it moves
         * to a liability to a PERSON, not to accounts payable, because an
         * employee is not a vendor and "what do we owe our staff" is a
         * figure somebody asks for on its own.
         */
        $lines = expenseLinesByAccount(expensePosting(
            costLines: [['account_id' => EXP_TRAVEL, 'amount' => '10000.0000']],
            claimableTaxes: [['account_id' => EXP_GST_INPUT, 'amount' => '1800.0000']],
            creditAccount: EXP_REIMBURSE,
        ));

        expect($lines[EXP_TRAVEL][0])->toBeDecimal('10000.0000')
            ->and($lines[EXP_REIMBURSE][1])->toBeDecimal('11800.0000')
            // Nothing left the bank: nobody has been paid back yet.
            ->and($lines)->not->toHaveKey(EXP_BANK);
    });

    it('capitalises input tax that cannot be reclaimed', function (): void {
        /*
         * The rule that bites hardest on expenses, because expenses are
         * where blocked input tax actually turns up: entertainment, staff
         * welfare, a car.
         *
         * The caller has already folded the tax into the cost, so 10,000 of
         * client entertainment at 18% arrives as one 11,800 debit.
         *
         * The alternative balances just as well — debit meals 10,000 and GST
         * Input 1,800 — and would put 1,800 of unrecoverable tax on the
         * balance sheet as an asset, understate the cost of the very thing
         * most likely to be questioned, and overstate profit until somebody
         * wrote the receivable off.
         */
        $lines = expenseLinesByAccount(expensePosting(
            costLines: [['account_id' => EXP_MEALS, 'amount' => '11800.0000']],
            claimableTaxes: [],
        ));

        expect($lines[EXP_MEALS][0])->toBeDecimal('11800.0000')
            ->and($lines[EXP_BANK][1])->toBeDecimal('11800.0000')
            // No receivable at all: there is nothing to reclaim.
            ->and($lines)->not->toHaveKey(EXP_GST_INPUT);
    });

    it('handles one claimable line and one blocked one on the same receipt', function (): void {
        /*
         * A hotel bill: the room is claimable, the bar is not. Both on one
         * receipt, which is exactly why claimability is decided per line.
         */
        $lines = expenseLinesByAccount(expensePosting(
            costLines: [
                ['account_id' => EXP_TRAVEL, 'amount' => '20000.0000'],
                // 5,000 plus the 900 that cannot be reclaimed.
                ['account_id' => EXP_MEALS, 'amount' => '5900.0000'],
            ],
            claimableTaxes: [['account_id' => EXP_GST_INPUT, 'amount' => '3600.0000']],
        ));

        expect($lines[EXP_TRAVEL][0])->toBeDecimal('20000.0000')
            ->and($lines[EXP_MEALS][0])->toBeDecimal('5900.0000')
            ->and($lines[EXP_GST_INPUT][0])->toBeDecimal('3600.0000')
            ->and($lines[EXP_BANK][1])->toBeDecimal('29500.0000');
    });

    it('debits an asset where what was bought is still worth something', function (): void {
        // A laptop is not an expense, and the rule does not care which the
        // account is — only that the cost lands where the reader will look.
        $lines = expenseLinesByAccount(expensePosting(
            costLines: [['account_id' => EXP_EQUIPMENT, 'amount' => '150000.0000']],
        ));

        expect($lines[EXP_EQUIPMENT][0])->toBeDecimal('150000.0000')
            ->and($lines[EXP_BANK][1])->toBeDecimal('150000.0000');
    });

    it('groups two lines charged to the same account into one journal line', function (): void {
        // A journal is a summary of a document, not a copy of it.
        $lines = expenseLinesByAccount(expensePosting(
            costLines: [
                ['account_id' => EXP_TRAVEL, 'amount' => '300.0000'],
                ['account_id' => EXP_TRAVEL, 'amount' => '700.0000'],
                ['account_id' => EXP_MEALS, 'amount' => '1000.0000'],
            ],
        ));

        expect($lines)->toHaveCount(3)
            ->and($lines[EXP_TRAVEL][0])->toBeDecimal('1000.0000')
            ->and($lines[EXP_MEALS][0])->toBeDecimal('1000.0000')
            ->and($lines[EXP_BANK][1])->toBeDecimal('2000.0000');
    });

    it('drops a line worth nothing', function (): void {
        $lines = expenseLinesByAccount(expensePosting(
            costLines: [
                ['account_id' => EXP_TRAVEL, 'amount' => '1000.0000'],
                ['account_id' => EXP_MEALS, 'amount' => '0.0000'],
            ],
            claimableTaxes: [['account_id' => EXP_GST_INPUT, 'amount' => '0.0000']],
        ));

        expect($lines)->toHaveCount(2)
            ->and($lines)->not->toHaveKey(EXP_MEALS)
            ->and($lines)->not->toHaveKey(EXP_GST_INPUT);
    });

    it('names the merchant in the memo', function (): void {
        /*
         * More important here than on a bill. An expense's payee is usually
         * not a contact record, so if the name is not in the memo it is
         * nowhere in the ledger at all — and "what was this 4,000 for" is the
         * question an auditor asks first.
         */
        $draft = expensePosting(
            costLines: [['account_id' => EXP_TRAVEL, 'amount' => '4000.0000']],
            payee: 'Careem',
        );

        expect($draft->memo)->toContain('EXP-000001')
            ->and($draft->memo)->toContain('Careem');
    });

    it('records the expense as its source, so a retried approval is refused', function (): void {
        $draft = expensePosting(
            costLines: [['account_id' => EXP_TRAVEL, 'amount' => '1000.0000']],
        );

        expect($draft->sourceType())->toBe('expense')
            ->and($draft->sourceId())->toBe('01926f00-0000-7000-8000-0000000exp1');
    });
});
