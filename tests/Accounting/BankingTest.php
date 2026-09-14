<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Banking\Actions\CompleteReconciliation;
use App\Domain\Banking\Actions\ConfirmStatementMatch;
use App\Domain\Banking\Actions\ImportBankStatement;
use App\Domain\Banking\Actions\RecordBankTransfer;
use App\Domain\Banking\Actions\SaveBankAccount;
use App\Domain\Banking\Actions\StartReconciliation;
use App\Domain\Banking\Actions\UnmatchStatementLine;
use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Banking\Enums\StatementFormat;
use App\Domain\Banking\Enums\StatementLineStatus;
use App\Domain\Banking\Exceptions\BankingRefused;
use App\Domain\Banking\Exceptions\StatementUnreadable;
use App\Domain\Banking\Models\BankStatementLine;
use App\Domain\Banking\Models\BankTransactionMatch;
use App\Domain\Banking\Services\MatchSuggester;
use App\Domain\Banking\Services\ReconciliationCalculator;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|---------------------------------------------------------------------------
| Banking
|---------------------------------------------------------------------------
|
| Phase 6. Three properties carry the whole phase, and each is asserted here
| against the alternative rather than merely demonstrated:
|
|   Importing a statement posts NOTHING. §8 — the bank's record is evidence
|   about our books, not an entry in them.
|
|   A match posts nothing either, and cannot be made wrongly: not on another
|   account, not in the other direction, not twice against the same entry,
|   and never for more than the line is worth. Those four are the ways a
|   reconciliation gets forced to zero while being wrong.
|
|   A completed reconciliation reconciles to zero AND cannot afterwards be
|   altered — by this code or any other, because the refusal is a database
|   trigger rather than a check in a controller.
|
| @see ACCOUNTING_RULES.md §4.9, §8
*/

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->actor = User::factory()->create();

    $this->post = app(PostJournalEntry::class);
    $this->import = app(ImportBankStatement::class);
    $this->confirm = app(ConfirmStatementMatch::class);
    $this->unmatch = app(UnmatchStatementLine::class);
    $this->start = app(StartReconciliation::class);
    $this->complete = app(CompleteReconciliation::class);
    $this->transfers = app(RecordBankTransfer::class);
    $this->suggester = app(MatchSuggester::class);
    $this->calculator = app(ReconciliationCalculator::class);

    $saveBankAccount = app(SaveBankAccount::class);

    $this->current = $saveBankAccount->handle([
        'account_id' => ledgerAccount('1020')->id,
        'name' => 'HBL Current',
        'bank_name' => 'Habib Bank',
        'account_number' => '0102938475',
        'kind' => 'bank',
    ], actor: $this->actor);

    $this->petty = $saveBankAccount->handle([
        'account_id' => ledgerAccount('1010')->id,
        'name' => 'Petty cash',
        'kind' => 'cash',
    ], actor: $this->actor);

    $this->revenue = ledgerAccount('4010');
    $this->rent = Account::query()->where('code', '6100')->first()
        ?? Account::query()->postable()->where('type', 'expense')->firstOrFail();

    /**
     * Post a receipt into, or a payment out of, the current account.
     *
     * Deliberately the ledger service directly rather than an invoice: what
     * is being tested here is banking, and the matcher cares only that a
     * posted line exists on the account.
     */
    $this->receipt = function (string $amount, string $on, ?string $memo = null): JournalEntry {
        return $this->post->handle(JournalDraft::inBaseCurrency(
            date: Carbon::parse($on),
            currency: $this->organization->base_currency,
            lines: [
                JournalLineDraft::debit(ledgerAccount('1020')->id, $amount, $memo),
                JournalLineDraft::credit($this->revenue->id, $amount, $memo),
            ],
            source: ['manual', (string) Str::uuid7(), 'issue'],
            memo: $memo,
        ), actor: $this->actor);
    };

    $this->payment = function (string $amount, string $on, ?string $memo = null): JournalEntry {
        return $this->post->handle(JournalDraft::inBaseCurrency(
            date: Carbon::parse($on),
            currency: $this->organization->base_currency,
            lines: [
                JournalLineDraft::debit($this->rent->id, $amount, $memo),
                JournalLineDraft::credit(ledgerAccount('1020')->id, $amount, $memo),
            ],
            source: ['manual', (string) Str::uuid7(), 'issue'],
            memo: $memo,
        ), actor: $this->actor);
    };

    $this->csv = function (string $body): string {
        return "Date,Description,Reference,Amount,Balance\n".$body;
    };
});

describe('bank accounts', function (): void {
    it('attaches to a ledger account and never invents one', function (): void {
        expect($this->current->account_id)->toBe(ledgerAccount('1020')->id)
            ->and($this->current->currency)->toBe($this->organization->base_currency);

        // Exactly the accounts the chart template created — banking added none.
        expect(Account::query()->where('code', '1020')->count())->toBe(1);
    });

    it('stores only the last four digits of the account number', function (): void {
        /*
         * A full account number is a payment instruction. It is not needed to
         * reconcile anything, and keeping it would put it in every backup and
         * export the business ever makes.
         */
        expect($this->current->account_number_masked)->toBe('••••8475')
            ->and($this->current->account_number_masked)->not->toContain('0102');
    });

    it('refuses a credit card on an asset account', function (): void {
        // A credit card is money OWED. On the asset side, every balance sheet
        // it appears on is wrong by twice the balance.
        expect(fn () => app(SaveBankAccount::class)->handle([
            'account_id' => ledgerAccount('1020')->id,
            'name' => 'Visa',
            'kind' => 'credit_card',
        ]))->toThrow(BankingRefused::class, 'liability account');
    });

    it('refuses to attach two bank accounts to one ledger account', function (): void {
        expect(fn () => app(SaveBankAccount::class)->handle([
            'account_id' => ledgerAccount('1020')->id,
            'name' => 'Second one',
            'kind' => 'bank',
        ]))->toThrow(BankingRefused::class, 'already belongs');
    });
});

describe('importing a statement', function (): void {
    it('posts absolutely nothing', function (): void {
        /*
         * §8's line in the table: "Bank statement import — No — Import is not
         * posting." The assertion is the count of journal entries before and
         * after, because that is the only thing that could be untrue.
         */
        $before = JournalEntry::query()->count();

        $import = $this->import->handle(
            $this->current,
            ($this->csv)(
                "01/07/2026,Transfer from Karachi Textiles,TFR9981,118000.00,318000.00\n".
                "03/07/2026,Office rent,,-45000.00,273000.00\n"
            ),
            'june.csv',
            StatementFormat::Csv,
            $this->actor,
        );

        expect($import->rows_imported)->toBe(2)
            ->and(JournalEntry::query()->count())->toBe($before)
            ->and(BankStatementLine::query()->count())->toBe(2);

        $inflow = BankStatementLine::query()->where('amount', '>', 0)->sole();

        expect($inflow->transaction_date->toDateString())->toBe('2026-07-01')
            ->and($inflow->amount)->toBeDecimal('118000.0000')
            ->and($inflow->reference)->toBe('TFR9981')
            ->and($inflow->status)->toBe(StatementLineStatus::Unmatched);
    });

    it('is a no-op when the same file is imported again', function (): void {
        $csv = ($this->csv)("01/07/2026,Rent,,-45000.00,\n02/07/2026,Sale,,118000.00,\n");

        $this->import->handle($this->current, $csv, 'june.csv', StatementFormat::Csv, $this->actor);
        $second = $this->import->handle($this->current, $csv, 'june.csv', StatementFormat::Csv, $this->actor);

        expect($second->rows_imported)->toBe(0)
            ->and($second->rows_duplicate)->toBe(2)
            ->and(BankStatementLine::query()->count())->toBe(2);
    });

    it('keeps two genuinely identical transactions on the same day', function (): void {
        /*
         * The other half of the same rule, and the reason the fingerprint
         * carries an occurrence number. Two identical fuel receipts on one
         * day are two transactions; collapsing them would leave the account
         * short by one of them for ever.
         */
        $this->import->handle(
            $this->current,
            ($this->csv)("01/07/2026,Fuel,,-4500.00,\n01/07/2026,Fuel,,-4500.00,\n"),
            'fuel.csv',
            StatementFormat::Csv,
            $this->actor,
        );

        expect(BankStatementLine::query()->count())->toBe(2);
    });

    it('reads a debit and credit pair as one signed amount', function (): void {
        $csv = "Txn Date,Narration,Withdrawal,Deposit,Balance\n".
            "01/07/2026,Sale,,\"118,000.00\",318000.00\n".
            "03/07/2026,Rent,\"45,000.00\",,273000.00\n";

        $this->import->handle($this->current, $csv, 'hbl.csv', StatementFormat::Csv, $this->actor);

        $amounts = BankStatementLine::query()->orderBy('transaction_date')->pluck('amount')->all();

        expect((string) $amounts[0])->toBeDecimal('118000.0000')
            ->and((string) $amounts[1])->toBeDecimal('-45000.0000');
    });

    it('reads the date order from the whole file rather than per row', function (): void {
        /*
         * 03/04/2026 is ambiguous; 13/04/2026 is not. One unambiguous row
         * settles the convention for every other row in the file, which is
         * the only way to avoid one statement with two conventions in it.
         */
        $this->import->handle(
            $this->current,
            ($this->csv)("03/04/2026,First,,1000.00,\n13/04/2026,Second,,2000.00,\n"),
            'ambiguous.csv',
            StatementFormat::Csv,
            $this->actor,
        );

        $dates = BankStatementLine::query()
            ->orderBy('transaction_date')
            ->pluck('transaction_date')
            ->map(static fn ($date): string => Carbon::parse((string) $date)->toDateString())
            ->all();

        expect($dates)->toBe(['2026-04-03', '2026-04-13']);
    });

    it('reads OFX, keeping the bank\'s own transaction id', function (): void {
        $ofx = <<<'OFX'
            OFXHEADER:100
            <OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>
            <STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20260701120000[+5:PKT]<TRNAMT>118000.00
            <FITID>202607010001<NAME>KARACHI TEXTILES<MEMO>INV-000001</STMTTRN>
            <STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260703<TRNAMT>-45000.00
            <FITID>202607030007<NAME>OFFICE RENT</STMTTRN>
            </BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>
            OFX;

        $this->import->handle($this->current, $ofx, 'statement.ofx', null, $this->actor);

        $line = BankStatementLine::query()->where('amount', '>', 0)->sole();

        expect($line->transaction_date->toDateString())->toBe('2026-07-01')
            ->and($line->amount)->toBeDecimal('118000.0000')
            ->and($line->reference)->toBe('202607010001')
            ->and($line->payee)->toBe('KARACHI TEXTILES');
    });

    it('reads QIF', function (): void {
        $qif = "!Type:Bank\nD01/07/2026\nT118,000.00\nPKarachi Textiles\nMINV-000001\nN9981\n^\n".
            "D03/07/2026\nT-45000.00\nPOffice rent\n^\n";

        $this->import->handle($this->current, $qif, 'statement.qif', null, $this->actor);

        expect(BankStatementLine::query()->count())->toBe(2);

        $line = BankStatementLine::query()->where('amount', '>', 0)->sole();

        expect($line->transaction_date->toDateString())->toBe('2026-07-01')
            ->and($line->amount)->toBeDecimal('118000.0000')
            ->and($line->reference)->toBe('9981');
    });

    it('refuses a file it cannot read rather than importing part of it', function (): void {
        expect(fn () => $this->import->handle(
            $this->current,
            "Date,Description,Amount\n01/07/2026,Sale,not a number\n",
            'broken.csv',
            StatementFormat::Csv,
            $this->actor,
        ))->toThrow(StatementUnreadable::class, 'not a number');

        expect(BankStatementLine::query()->count())->toBe(0);
    });

    it('refuses to import a statement for a cash account', function (): void {
        expect(fn () => $this->import->handle(
            $this->petty,
            ($this->csv)("01/07/2026,Anything,,100.00,\n"),
            'cash.csv',
            StatementFormat::Csv,
            $this->actor,
        ))->toThrow(BankingRefused::class, 'no statement');
    });
});

describe('matching', function (): void {
    beforeEach(function (): void {
        ($this->receipt)('118000.00', '2026-07-01', 'INV-000001 · Karachi Textiles');
        ($this->payment)('45000.00', '2026-07-02', 'Office rent');

        $this->import->handle(
            $this->current,
            ($this->csv)(
                "01/07/2026,TFR KARACHI TEXTILE INV-000001,INV-000001,118000.00,\n".
                "03/07/2026,OFFICE RENT JULY,,-45000.00,\n"
            ),
            'july.csv',
            StatementFormat::Csv,
            $this->actor,
        );

        $this->inflowLine = BankStatementLine::query()->where('amount', '>', 0)->sole();
        $this->outflowLine = BankStatementLine::query()->where('amount', '<', 0)->sole();
    });

    it('suggests only entries of exactly the same amount', function (): void {
        /*
         * A near-amount suggestion is how a reconciliation is forced to zero
         * while being wrong. 12,450 accepted against 12,540 makes the
         * difference vanish into a matched pair, and nothing on any screen
         * says so afterwards.
         */
        ($this->receipt)('118000.50', '2026-07-01', 'Nearly the same');

        $suggestions = $this->suggester->for($this->inflowLine);

        expect($suggestions)->toHaveCount(1)
            ->and($suggestions[0]->amount)->toBeDecimal('118000.0000')
            ->and($suggestions[0]->reasons[0])->toContain('exactly the same');
    });

    it('explains itself in words, not only a score', function (): void {
        $best = $this->suggester->for($this->inflowLine)[0];

        expect($best->isStrong())->toBeTrue()
            ->and(implode(' ', $best->reasons))->toContain('INV-000001');
    });

    it('posts nothing when a match is confirmed', function (): void {
        $before = JournalEntry::query()->count();

        $suggestion = $this->suggester->for($this->inflowLine)[0];

        $match = $this->confirm->handle(
            line: $this->inflowLine,
            journalLineId: $suggestion->journalLineId,
            actor: $this->actor,
            origin: 'suggested',
            confidence: $suggestion->confidence,
        );

        expect(JournalEntry::query()->count())->toBe($before)
            ->and($match->amount)->toBeDecimal('118000.0000')
            ->and($match->matched_by)->toBe($this->actor->id)
            ->and($this->inflowLine->refresh()->status)->toBe(StatementLineStatus::Matched);
    });

    it('refuses an entry that moves money the other way', function (): void {
        // The rent payment against the deposit: same account, same period,
        // and completely wrong.
        $rentLine = DB::table('journal_lines')
            ->where('account_id', ledgerAccount('1020')->id)
            ->where('credit', '>', 0)
            ->value('id');

        expect(fn () => $this->confirm->handle(
            line: $this->inflowLine,
            journalLineId: (string) $rentLine,
            actor: $this->actor,
        ))->toThrow(BankingRefused::class, 'the other way');
    });

    it('refuses an entry that is not on this bank account', function (): void {
        $revenueLine = DB::table('journal_lines')
            ->where('account_id', $this->revenue->id)
            ->value('id');

        expect(fn () => $this->confirm->handle(
            line: $this->inflowLine,
            journalLineId: (string) $revenueLine,
            actor: $this->actor,
        ))->toThrow(BankingRefused::class, 'not on this bank account');
    });

    it('refuses to clear one entry against two statement lines', function (): void {
        /*
         * The unique index on journal_line_id, as a rule somebody meets in
         * words. Matching one payment to two bank lines is precisely how a
         * reconciliation reaches zero with money missing.
         */
        $this->import->handle(
            $this->current,
            ($this->csv)("05/07/2026,TFR KARACHI TEXTILE AGAIN,,118000.00,\n"),
            'again.csv',
            StatementFormat::Csv,
            $this->actor,
        );

        $second = BankStatementLine::query()
            ->where('transaction_date', '2026-07-05')
            ->sole();

        $suggestion = $this->suggester->for($this->inflowLine)[0];

        $this->confirm->handle($this->inflowLine, $suggestion->journalLineId, $this->actor);

        expect(fn () => $this->confirm->handle(
            line: $second,
            journalLineId: $suggestion->journalLineId,
            actor: $this->actor,
        ))->toThrow(BankingRefused::class, 'already been matched');
    });

    it('lets one deposit be explained by two entries, and only then counts it matched', function (): void {
        ($this->receipt)('30000.00', '2026-07-10', 'First half');
        ($this->receipt)('20000.00', '2026-07-10', 'Second half');

        $this->import->handle(
            $this->current,
            ($this->csv)("10/07/2026,BULK DEPOSIT,,50000.00,\n"),
            'bulk.csv',
            StatementFormat::Csv,
            $this->actor,
        );

        $deposit = BankStatementLine::query()->where('transaction_date', '2026-07-10')->sole();

        $halves = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', ledgerAccount('1020')->id)
            ->where('je.entry_date', '2026-07-10')
            ->where('jl.debit', '>', 0)
            ->pluck('jl.id')
            ->all();

        $this->confirm->handle($deposit, (string) $halves[0], $this->actor);

        // Partly explained is not matched: the honest state, and the one the
        // reconciliation figures must reflect.
        expect($deposit->refresh()->status)->toBe(StatementLineStatus::Unmatched);

        $this->confirm->handle($deposit, (string) $halves[1], $this->actor);

        expect($deposit->refresh()->status)->toBe(StatementLineStatus::Matched);
    });

    it('refuses to match more than the line is worth', function (): void {
        ($this->receipt)('30000.00', '2026-07-10', 'Part');
        ($this->receipt)('118000.00', '2026-07-10', 'Too much');

        $this->import->handle(
            $this->current,
            ($this->csv)("10/07/2026,SMALL DEPOSIT,,30000.00,\n"),
            'small.csv',
            StatementFormat::Csv,
            $this->actor,
        );

        $deposit = BankStatementLine::query()->where('transaction_date', '2026-07-10')->sole();

        $tooBig = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', ledgerAccount('1020')->id)
            ->where('je.entry_date', '2026-07-10')
            ->where('jl.debit', '118000.0000')
            ->value('jl.id');

        expect(fn () => $this->confirm->handle($deposit, (string) $tooBig, $this->actor))
            ->toThrow(BankingRefused::class, 'left to match');
    });

    it('can be undone while the reconciliation is open', function (): void {
        $suggestion = $this->suggester->for($this->inflowLine)[0];

        $this->confirm->handle($this->inflowLine, $suggestion->journalLineId, $this->actor);
        $this->unmatch->handle($this->inflowLine, $this->actor);

        expect($this->inflowLine->refresh()->status)->toBe(StatementLineStatus::Unmatched)
            ->and(BankTransactionMatch::query()->count())->toBe(0);
    });

    it('requires a reason to exclude a line', function (): void {
        $this->unmatch->exclude($this->outflowLine, 'Duplicated by the feed', $this->actor);

        expect($this->outflowLine->refresh()->status)->toBe(StatementLineStatus::Excluded)
            ->and($this->outflowLine->excluded_reason)->toBe('Duplicated by the feed');

        // And the database says so too, whatever code path asks.
        expect(refused(fn () => DB::table('bank_statement_lines')
            ->where('id', $this->inflowLine->id)
            ->update(['status' => 'excluded', 'excluded_reason' => null])))
            ->toThrow(QueryException::class);
    });
});

describe('reconciling', function (): void {
    beforeEach(function (): void {
        ($this->receipt)('118000.00', '2026-07-01', 'INV-000001');
        ($this->payment)('45000.00', '2026-07-02', 'Office rent');

        $this->import->handle(
            $this->current,
            ($this->csv)(
                "01/07/2026,TFR INV-000001,INV-000001,118000.00,118000.00\n".
                "03/07/2026,OFFICE RENT,,-45000.00,73000.00\n"
            ),
            'july.csv',
            StatementFormat::Csv,
            $this->actor,
        );

        $this->matchEverything = function (): void {
            foreach (BankStatementLine::query()->outstanding()->get() as $line) {
                $suggestions = $this->suggester->for($line);

                expect($suggestions)->not->toBeEmpty();

                $this->confirm->handle($line, $suggestions[0]->journalLineId, $this->actor);
            }
        };
    });

    it('completes at zero, and only at zero', function (): void {
        $reconciliation = $this->start->handle(
            bankAccount: $this->current,
            periodStart: Carbon::parse('2026-07-01'),
            periodEnd: Carbon::parse('2026-07-31'),
            closingBalance: '73000.00',
            openingBalance: '0',
            actor: $this->actor,
        );

        // Nothing matched yet: out by the whole statement.
        expect(fn () => $this->complete->handle($reconciliation, $this->actor))
            ->toThrow(BankingRefused::class, 'out by');

        ($this->matchEverything)();

        $completed = $this->complete->handle($reconciliation->refresh(), $this->actor);

        expect($completed->status)->toBe(ReconciliationStatus::Completed)
            ->and($completed->difference)->toBeDecimal('0.0000')
            ->and($completed->cleared_balance)->toBeDecimal('73000.0000')
            ->and($completed->completed_by)->toBe($this->actor->id);
    });

    it('reconciles the BOOKS against the bank, not a file against itself', function (): void {
        /*
         * The identity that makes this a reconciliation at all:
         *
         *     cleared = ledger balance − what has not cleared the bank
         *
         * An unpresented cheque — ours, posted, not yet on any statement — is
         * exactly the difference between the two sides, and it must be
         * visible rather than absorbed.
         */
        ($this->payment)('10000.00', '2026-07-20', 'Cheque not presented');

        $reconciliation = $this->start->handle(
            bankAccount: $this->current,
            periodStart: Carbon::parse('2026-07-01'),
            periodEnd: Carbon::parse('2026-07-31'),
            closingBalance: '73000.00',
            openingBalance: '0',
            actor: $this->actor,
        );

        ($this->matchEverything)();

        $figures = $this->calculator->figuresFor($reconciliation->refresh());

        expect($figures['difference'])->toBeDecimal('0.0000')
            ->and($figures['ledger_balance'])->toBeDecimal('63000.0000')
            ->and($figures['unpresented_count'])->toBe(1)
            ->and($figures['unpresented_total'])->toBeDecimal('-10000.0000');

        // 63,000 − (−10,000) = 73,000, which is what the bank says.
        expect(
            bcsub($figures['ledger_balance'], $figures['unpresented_total'], 4)
        )->toBeDecimal($figures['cleared']);
    });

    it('freezes its statement lines and matches once completed', function (): void {
        $reconciliation = $this->start->handle(
            bankAccount: $this->current,
            periodStart: Carbon::parse('2026-07-01'),
            periodEnd: Carbon::parse('2026-07-31'),
            closingBalance: '73000.00',
            openingBalance: '0',
            actor: $this->actor,
        );

        ($this->matchEverything)();
        $this->complete->handle($reconciliation->refresh(), $this->actor);

        $line = BankStatementLine::query()->where('amount', '>', 0)->sole();

        expect($line->reconciliation_id)->toBe($reconciliation->id);

        // Through the domain, in words.
        expect(fn () => $this->unmatch->handle($line, $this->actor))
            ->toThrow(BankingRefused::class, 'completed reconciliation');

        /*
         * And through raw SQL, which is the assertion that matters: the
         * refusal is a database trigger, so a console command, a future
         * import path or a stray ->update() all meet it too.
         */
        expect(refused(fn () => DB::table('bank_statement_lines')
            ->where('id', $line->id)
            ->update(['amount' => '1.00'])))
            ->toThrow(QueryException::class, 'cannot be changed');

        expect(refused(fn () => DB::table('bank_transaction_matches')
            ->where('statement_line_id', $line->id)
            ->delete()))
            ->toThrow(QueryException::class, 'cannot be changed');
    });

    it('cannot be reopened or deleted', function (): void {
        $reconciliation = $this->start->handle(
            bankAccount: $this->current,
            periodStart: Carbon::parse('2026-07-01'),
            periodEnd: Carbon::parse('2026-07-31'),
            closingBalance: '73000.00',
            openingBalance: '0',
            actor: $this->actor,
        );

        ($this->matchEverything)();
        $this->complete->handle($reconciliation->refresh(), $this->actor);

        expect(fn () => $this->complete->handle($reconciliation->refresh(), $this->actor))
            ->toThrow(BankingRefused::class, 'is completed');

        expect(refused(fn () => DB::table('bank_reconciliations')
            ->where('id', $reconciliation->id)
            ->update(['status' => 'draft'])))
            ->toThrow(QueryException::class, 'cannot be reopened');

        expect(refused(fn () => DB::table('bank_reconciliations')
            ->where('id', $reconciliation->id)
            ->delete()))
            ->toThrow(QueryException::class, 'cannot be reopened');
    });

    it('carries its closing balance into the next period', function (): void {
        $july = $this->start->handle(
            bankAccount: $this->current,
            periodStart: Carbon::parse('2026-07-01'),
            periodEnd: Carbon::parse('2026-07-31'),
            closingBalance: '73000.00',
            openingBalance: '0',
            actor: $this->actor,
        );

        ($this->matchEverything)();
        $this->complete->handle($july->refresh(), $this->actor);

        $august = $this->start->handle(
            bankAccount: $this->current,
            periodStart: Carbon::parse('2026-08-01'),
            periodEnd: Carbon::parse('2026-08-31'),
            closingBalance: '73000.00',
            // Ignored: the chain is what it is, and typing over it would
            // break every period after this one.
            openingBalance: '999999.00',
            actor: $this->actor,
        );

        expect($august->opening_balance)->toBeDecimal('73000.0000');
    });

    it('refuses a second one while the first is open, and one that overlaps a completed period', function (): void {
        $july = $this->start->handle(
            bankAccount: $this->current,
            periodStart: Carbon::parse('2026-07-01'),
            periodEnd: Carbon::parse('2026-07-31'),
            closingBalance: '73000.00',
            openingBalance: '0',
            actor: $this->actor,
        );

        expect(fn () => $this->start->handle(
            bankAccount: $this->current,
            periodStart: Carbon::parse('2026-08-01'),
            periodEnd: Carbon::parse('2026-08-31'),
            closingBalance: '0',
            actor: $this->actor,
        ))->toThrow(BankingRefused::class, 'still open');

        ($this->matchEverything)();
        $this->complete->handle($july->refresh(), $this->actor);

        expect(fn () => $this->start->handle(
            bankAccount: $this->current,
            periodStart: Carbon::parse('2026-07-15'),
            periodEnd: Carbon::parse('2026-08-15'),
            closingBalance: '0',
            actor: $this->actor,
        ))->toThrow(BankingRefused::class, 'already reconciled up to');
    });
});

describe('transfers', function (): void {
    it('posts §4.9 and touches neither income nor expense', function (): void {
        $transfer = $this->transfers->handle(
            from: $this->current,
            to: $this->petty,
            amount: '25000.00',
            transferDate: Carbon::parse('2026-07-05'),
            reference: 'Float for the shop',
            actor: $this->actor,
        );

        $entry = JournalEntry::query()->findOrFail($transfer->journal_entry_id);
        $lines = entryLines($entry);

        expect($lines['1010']['debit'])->toBeDecimal('25000.0000')
            ->and($lines['1020']['credit'])->toBeDecimal('25000.0000')
            ->and($lines)->toHaveCount(2);

        foreach ($entry->lines()->with('account')->get() as $line) {
            expect($line->account?->type)->toBe(AccountType::Asset);
        }
    });

    it('refuses to move money to where it already is', function (): void {
        expect(fn () => $this->transfers->handle(
            from: $this->current,
            to: $this->current,
            amount: '100.00',
            transferDate: Carbon::parse('2026-07-05'),
            actor: $this->actor,
        ))->toThrow(BankingRefused::class, 'two different accounts');
    });

    it('books the cost of converting as FX rather than hiding it in a balance', function (): void {
        $usdAccount = Account::query()->create([
            'code' => '1025',
            'name' => 'USD Account',
            'type' => AccountType::Asset,
            'normal_balance' => NormalBalance::Debit,
            'currency' => 'USD',
        ]);

        $usd = app(SaveBankAccount::class)->handle([
            'account_id' => $usdAccount->id,
            'name' => 'Citi USD',
            'kind' => 'bank',
        ], actor: $this->actor);

        /*
         * 280,000 PKR left; 990 USD arrived, worth 277,200 PKR at 280. The
         * 2,800 the bank kept is a cost, and it belongs in FX gain and loss
         * rather than quietly in one of the two bank balances — where it
         * would leave that account permanently unable to reconcile.
         */
        $transfer = $this->transfers->handle(
            from: $this->current,
            to: $usd,
            amount: '280000.00',
            transferDate: Carbon::parse('2026-07-06'),
            amountReceived: '990.00',
            exchangeRate: '1',
            destinationExchangeRate: '280',
            actor: $this->actor,
        );

        $lines = entryLines(JournalEntry::query()->findOrFail($transfer->journal_entry_id));

        expect($lines['1020']['credit'])->toBeDecimal('280000.0000')
            ->and($lines['1025']['debit'])->toBeDecimal('277200.0000')
            ->and($lines[ledgerAccount(SystemAccount::FxGainLoss)->code]['debit'])
            ->toBeDecimal('2800.0000');
    });

    it('refuses a cross-currency transfer that does not say how much arrived', function (): void {
        $usdAccount = Account::query()->create([
            'code' => '1026',
            'name' => 'USD Account',
            'type' => AccountType::Asset,
            'normal_balance' => NormalBalance::Debit,
            'currency' => 'USD',
        ]);

        $usd = app(SaveBankAccount::class)->handle([
            'account_id' => $usdAccount->id,
            'name' => 'Citi USD',
            'kind' => 'bank',
        ], actor: $this->actor);

        expect(fn () => $this->transfers->handle(
            from: $this->current,
            to: $usd,
            amount: '280000.00',
            transferDate: Carbon::parse('2026-07-06'),
            actor: $this->actor,
        ))->toThrow(InvalidArgumentException::class, 'how much arrived');
    });

    it('is voided by reversal, never by deletion', function (): void {
        $transfer = $this->transfers->handle(
            from: $this->current,
            to: $this->petty,
            amount: '25000.00',
            transferDate: Carbon::parse('2026-07-05'),
            actor: $this->actor,
        );

        $voided = $this->transfers->void($transfer, $this->actor, reason: 'Never happened');

        expect($voided->voided_at)->not->toBeNull()
            ->and($voided->void_journal_entry_id)->not->toBeNull();

        $reversal = JournalEntry::query()->findOrFail($voided->void_journal_entry_id);
        $lines = entryLines($reversal);

        // Mirrored, not deleted.
        expect($lines['1010']['credit'])->toBeDecimal('25000.0000')
            ->and($lines['1020']['debit'])->toBeDecimal('25000.0000');
    });

    it('cannot be voided once it has been reconciled', function (): void {
        $transfer = $this->transfers->handle(
            from: $this->current,
            to: $this->petty,
            amount: '25000.00',
            transferDate: Carbon::parse('2026-07-05'),
            actor: $this->actor,
        );

        $this->import->handle(
            $this->current,
            ($this->csv)("05/07/2026,TRANSFER TO PETTY CASH,,-25000.00,-25000.00\n"),
            'transfer.csv',
            StatementFormat::Csv,
            $this->actor,
        );

        $line = BankStatementLine::query()->sole();
        $suggestion = $this->suggester->for($line)[0];

        $this->confirm->handle($line, $suggestion->journalLineId, $this->actor);

        $reconciliation = $this->start->handle(
            bankAccount: $this->current,
            periodStart: Carbon::parse('2026-07-01'),
            periodEnd: Carbon::parse('2026-07-31'),
            closingBalance: '-25000.00',
            openingBalance: '0',
            actor: $this->actor,
        );

        $this->complete->handle($reconciliation, $this->actor);

        expect(fn () => $this->transfers->void($transfer->refresh(), $this->actor))
            ->toThrow(BankingRefused::class, 'has been reconciled');
    });
});
