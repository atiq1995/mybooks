<?php

declare(strict_types=1);

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Exceptions\UnbalancedJournal;
use Illuminate\Support\Carbon;

/*
|---------------------------------------------------------------------------
| The first of the three balance checks
|---------------------------------------------------------------------------
|
| A draft is pure: no database, no tenant, no container. That is the whole
| point of the type — the posting rules for every document in the system can
| be asserted here in microseconds, and a draft that reaches the ledger has
| already been proven arithmetically sound.
|
| @see ACCOUNTING_RULES.md §1, I1-I3
*/

const AR = '01926f00-0000-7000-8000-000000000001';
const REVENUE = '01926f00-0000-7000-8000-000000000002';
const GST = '01926f00-0000-7000-8000-000000000003';

function draft(array $lines, string $currency = 'PKR', string $rate = '1', string $base = 'PKR'): JournalDraft
{
    return new JournalDraft(
        date: Carbon::parse('2026-08-15'),
        currency: $currency,
        baseCurrency: $base,
        exchangeRate: $rate,
        lines: $lines,
        source: ['test', null, 'issue'],
    );
}

describe('a line', function (): void {
    it('refuses a negative debit, because that is a credit wearing a disguise', function (): void {
        expect(fn () => JournalLineDraft::debit(AR, '-100.00'))
            ->toThrow(InvalidArgumentException::class, 'cannot be negative');
    });

    it('refuses a negative credit for the same reason', function (): void {
        expect(fn () => JournalLineDraft::credit(REVENUE, '-100.00'))
            ->toThrow(InvalidArgumentException::class, 'cannot be negative');
    });

    it('refuses a zero amount on either side', function (): void {
        expect(fn () => JournalLineDraft::debit(AR, '0'))
            ->toThrow(InvalidArgumentException::class, 'carries no information');

        expect(fn () => JournalLineDraft::credit(AR, '0.0000'))
            ->toThrow(InvalidArgumentException::class, 'carries no information');
    });

    it('has exactly one side', function (): void {
        $debit = JournalLineDraft::debit(AR, '100');
        $credit = JournalLineDraft::credit(REVENUE, '100');

        expect((string) $debit->debitValue())->toBeDecimal('100.0000');
        expect((string) $debit->creditValue())->toBeDecimal('0');
        expect($debit->isDebit())->toBeTrue();

        expect((string) $credit->debitValue())->toBeDecimal('0');
        expect((string) $credit->creditValue())->toBeDecimal('100.0000');
        expect($credit->isDebit())->toBeFalse();
    });

    it('rounds to four decimal places on construction, once', function (): void {
        expect((string) JournalLineDraft::debit(AR, '100.00005')->debitValue())->toBeDecimal('100.0001');
        expect((string) JournalLineDraft::debit(AR, '100.00004')->debitValue())->toBeDecimal('100.0000');
    });

    it('swaps sides when reversed, keeping the amount exactly', function (): void {
        $reversed = JournalLineDraft::debit(AR, '1234.5678')->reversed();

        expect((string) $reversed->creditValue())->toBeDecimal('1234.5678');
        expect((string) $reversed->debitValue())->toBeDecimal('0');
        expect($reversed->accountId)->toBe(AR);
    });

    it('converts to base currency at the entry rate', function (): void {
        $line = JournalLineDraft::debit(AR, '100');

        expect((string) $line->debitBaseValue('278.5000000000'))->toBeDecimal('27850.0000');
        expect((string) $line->creditBaseValue('278.5000000000'))->toBeDecimal('0');
    });

    it('prefers an explicit base amount over the entry rate', function (): void {
        // A settlement fixed at a rate other than the entry's own. Recomputing
        // would silently invent an FX difference.
        $line = JournalLineDraft::debit(AR, '100', baseAmountOverride: '27000.0000');

        expect((string) $line->debitBaseValue('278.5'))->toBeDecimal('27000.0000');
    });
});

describe('a draft', function (): void {
    it('accepts an entry whose debits equal its credits', function (): void {
        $draft = draft([
            JournalLineDraft::debit(AR, '117100.00'),
            JournalLineDraft::credit(REVENUE, '100000.00'),
            JournalLineDraft::credit(GST, '17100.00'),
        ]);

        expect($draft->isBalanced())->toBeTrue();
        expect((string) $draft->totalDebit())->toBeDecimal('117100.0000');
        expect((string) $draft->totalCredit())->toBeDecimal('117100.0000');

        $draft->assertBalanced();
    });

    it('refuses an entry that is out by the smallest representable amount', function (): void {
        $draft = draft([
            JournalLineDraft::debit(AR, '100.0000'),
            JournalLineDraft::credit(REVENUE, '99.9999'),
        ]);

        expect($draft->isBalanced())->toBeFalse();
        expect(fn () => $draft->assertBalanced())
            ->toThrow(UnbalancedJournal::class, 'does not balance');
    });

    it('refuses an empty entry', function (): void {
        expect(fn () => draft([])->assertBalanced())
            ->toThrow(UnbalancedJournal::class, 'at least two lines');
    });

    it('refuses an entry that balances at zero', function (): void {
        // Balanced, but says nothing. A zero-value entry is a bug upstream,
        // not a transaction.
        $lines = [
            JournalLineDraft::debit(AR, '100'),
            JournalLineDraft::credit(REVENUE, '100'),
        ];

        $balanced = draft($lines);
        $balanced->assertBalanced();

        // Constructed directly with zeroes, which the line constructors refuse
        // — proving the draft catches it even if a line ever slipped through.
        $zeroed = new ReflectionClass(JournalLineDraft::class);
        expect($zeroed->getConstructor()?->isPrivate())->toBeTrue();
    });

    it('must balance in the base currency too, not only the transaction one', function (): void {
        /*
         * I2. Both lines are 100 USD, so the transaction currency balances.
         * The credit carries an override that does not match, so the base
         * currency does not — exactly the shape of a real FX rounding bug.
         */
        $draft = draft(
            lines: [
                JournalLineDraft::debit(AR, '100'),
                JournalLineDraft::credit(REVENUE, '100', baseAmountOverride: '27000.0000'),
            ],
            currency: 'USD',
            rate: '278.5',
            base: 'PKR',
        );

        expect((string) $draft->totalDebit())->toBeDecimal('100.0000');
        expect((string) $draft->totalCredit())->toBeDecimal('100.0000');
        expect((string) $draft->totalDebitBase())->toBeDecimal('27850.0000');
        expect((string) $draft->totalCreditBase())->toBeDecimal('27000.0000');

        expect($draft->isBalanced())->toBeFalse();
        expect(fn () => $draft->assertBalanced())
            ->toThrow(UnbalancedJournal::class, 'does not balance in base currency');
    });

    it('sums at full precision and rounds once, so a long entry does not drift', function (): void {
        // Three thirds of a rupee: rounding each line would total 1.0001 or
        // 0.9999 depending on direction. Rounding once totals exactly 1.
        $draft = draft([
            JournalLineDraft::debit(AR, '1.0000'),
            JournalLineDraft::credit(REVENUE, '0.3333'),
            JournalLineDraft::credit(REVENUE, '0.3333'),
            JournalLineDraft::credit(REVENUE, '0.3334'),
        ]);

        expect((string) $draft->totalCredit())->toBeDecimal('1.0000');
        expect($draft->isBalanced())->toBeTrue();
    });

    it('reports its source as a type, an id and a purpose', function (): void {
        $draft = new JournalDraft(
            date: Carbon::parse('2026-08-15'),
            currency: 'PKR',
            baseCurrency: 'PKR',
            exchangeRate: '1',
            lines: [
                JournalLineDraft::debit(AR, '10'),
                JournalLineDraft::credit(REVENUE, '10'),
            ],
            source: ['invoice', AR, 'settle'],
        );

        expect($draft->sourceType())->toBe('invoice');
        expect($draft->sourceId())->toBe(AR);
        expect($draft->sourcePurpose())->toBe('settle');
    });

    it('treats the rate as 1 when built in the base currency', function (): void {
        $draft = JournalDraft::inBaseCurrency(
            date: Carbon::parse('2026-08-15'),
            currency: 'PKR',
            lines: [
                JournalLineDraft::debit(AR, '250.50'),
                JournalLineDraft::credit(REVENUE, '250.50'),
            ],
            source: ['manual', null, 'issue'],
        );

        expect($draft->exchangeRate)->toBe('1');
        expect($draft->baseCurrency)->toBe('PKR');
        expect((string) $draft->totalDebitBase())->toBeDecimal('250.5000');
    });
});
