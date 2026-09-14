<?php

declare(strict_types=1);

use App\Domain\Access\Enums\Role;
use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Banking\Actions\SaveBankAccount;
use App\Domain\Banking\Actions\StartReconciliation;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankReconciliation;
use App\Domain\Banking\Models\BankStatementLine;
use App\Domain\Banking\Models\BankTransfer;
use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
|---------------------------------------------------------------------------
| The banking screens
|---------------------------------------------------------------------------
|
| The domain tests prove the arithmetic and the invariants. These cover the
| HTTP layer, where the separation of duties actually lives:
|
|   importing needs `banking.import`, which a bookkeeper has, because import
|   changes nothing;
|
|   matching and reconciling need `banking.reconcile`, which a bookkeeper does
|   NOT have, because those are the judgements the books rest on;
|
|   recording a transfer needs `banking.transfer` AND `accounting.post`,
|   because it is the one thing here that writes in the ledger.
|
| Plus the two things every screen in this product has to prove: another
| organisation's row is a 404, and the controls shown match the rules the
| routes enforce.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-20');

    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->owner = actingAsMember($this->organization, Role::Owner->value);

    $this->current = app(SaveBankAccount::class)->handle([
        'account_id' => ledgerAccount('1020')->id,
        'name' => 'HBL Current',
        'bank_name' => 'Habib Bank',
        'account_number' => '0102938475',
        'kind' => 'bank',
        'is_primary' => true,
    ], actor: $this->owner);

    $this->petty = app(SaveBankAccount::class)->handle([
        'account_id' => ledgerAccount('1010')->id,
        'name' => 'Petty cash',
        'kind' => 'cash',
    ], actor: $this->owner);

    $this->statement = fn (string $body): UploadedFile => UploadedFile::fake()->createWithContent(
        'statement.csv',
        "Date,Description,Reference,Amount\n".$body,
    );

    $this->receipt = function (string $amount, string $on, ?string $memo = null): JournalEntry {
        return app(PostJournalEntry::class)->handle(JournalDraft::inBaseCurrency(
            date: Carbon::parse($on),
            currency: $this->organization->base_currency,
            lines: [
                JournalLineDraft::debit(ledgerAccount('1020')->id, $amount, $memo),
                JournalLineDraft::credit(ledgerAccount('4010')->id, $amount, $memo),
            ],
            source: ['manual', (string) Str::uuid7(), 'issue'],
            memo: $memo,
        ), actor: $this->owner);
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('serves the four screens', function (): void {
    $this->get('/banking/accounts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Banking/Accounts'));

    $this->get('/banking/transactions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Banking/Transactions'));

    $this->get('/banking/reconciliation')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Banking/Reconciliation'));

    $this->get('/banking/transfers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Banking/Transfers'));
});

it('404s on a banking section that does not exist', function (): void {
    // The module has landed, so the placeholder no longer stands in for it.
    $this->get('/banking/widgets')->assertNotFound();
});

it('shows balances that came from the ledger', function (): void {
    ($this->receipt)('118000.00', '2026-07-01', 'INV-000001');

    $this->get('/banking/accounts')
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.0.balance', '118000.0000')
            ->where('accounts.0.account.code', '1020'),
        );
});

it('refuses a credit card on an asset account, in words', function (): void {
    $this->post('/banking/accounts', [
        'account_id' => ledgerAccount('1010')->id,
        'name' => 'Visa',
        'kind' => 'credit_card',
    ])->assertSessionHasErrors('account_id');
});

describe('importing', function (): void {
    it('imports a statement and says plainly that nothing posted', function (): void {
        $before = JournalEntry::query()->count();

        $this->post(
            "/banking/accounts/{$this->current->id}/import",
            ['statement' => ($this->statement)("01/07/2026,Deposit,TFR1,118000.00\n")],
        )->assertSessionHas('success');

        expect(session('success'))->toContain('Nothing has been posted')
            ->and(BankStatementLine::query()->count())->toBe(1)
            ->and(JournalEntry::query()->count())->toBe($before);
    });

    it('reports an unreadable file as a field error rather than an exception', function (): void {
        $this->post(
            "/banking/accounts/{$this->current->id}/import",
            ['statement' => UploadedFile::fake()->createWithContent('notes.csv', "hello\nworld\n")],
        )->assertSessionHasErrors('statement');

        expect(BankStatementLine::query()->count())->toBe(0);
    });

    it('refuses a statement for another organisation\'s account', function (): void {
        $other = Organization::factory()->create();

        $theirs = app(TenantContext::class)->runAs($other, function () use ($other): BankAccount {
            withLedger($other);

            return app(SaveBankAccount::class)->handle([
                'account_id' => ledgerAccount('1020')->id,
                'name' => 'Theirs',
                'kind' => 'bank',
            ]);
        });

        actingAsMember($this->organization, Role::Owner->value);

        $this->post(
            "/banking/accounts/{$theirs->id}/import",
            ['statement' => ($this->statement)("01/07/2026,Deposit,,100.00\n")],
        )->assertNotFound();
    });
});

describe('matching', function (): void {
    beforeEach(function (): void {
        ($this->receipt)('118000.00', '2026-07-01', 'INV-000001');

        $this->post(
            "/banking/accounts/{$this->current->id}/import",
            ['statement' => ($this->statement)("01/07/2026,TFR INV-000001,INV-000001,118000.00\n")],
        );

        $this->line = BankStatementLine::query()->sole();

        $this->journalLineId = (string) DB::table('journal_lines')
            ->where('account_id', ledgerAccount('1020')->id)
            ->where('debit', '>', 0)
            ->value('id');
    });

    it('offers suggestions for the line that is open, and only that one', function (): void {
        $this->get("/banking/transactions?line={$this->line->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedLine.id', $this->line->id)
                ->has('suggestions', 1)
                ->where('suggestions.0.amount', '118000.0000'),
            );

        // No line chosen, no suggestions computed.
        $this->get('/banking/transactions')
            ->assertInertia(fn (Assert $page) => $page->has('suggestions', 0));
    });

    it('matches, and posts nothing doing it', function (): void {
        $before = JournalEntry::query()->count();

        $this->post("/banking/transactions/{$this->line->id}/match", [
            'journal_line_id' => $this->journalLineId,
            'origin' => 'suggested',
            'confidence' => 90,
        ])->assertSessionHas('success');

        expect($this->line->refresh()->status->value)->toBe('matched')
            ->and(JournalEntry::query()->count())->toBe($before);
    });

    it('turns a refusal into a message rather than a 500', function (): void {
        $revenueLine = (string) DB::table('journal_lines')
            ->where('account_id', ledgerAccount('4010')->id)
            ->value('id');

        $this->post("/banking/transactions/{$this->line->id}/match", [
            'journal_line_id' => $revenueLine,
        ])->assertSessionHas('error');

        expect(session('error'))->toContain('not on this bank account')
            ->and($this->line->refresh()->status->value)->toBe('unmatched');
    });

    it('requires a reason to exclude', function (): void {
        $this->post("/banking/transactions/{$this->line->id}/exclude", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->post("/banking/transactions/{$this->line->id}/exclude", [
            'reason' => 'Duplicated by the feed',
        ])->assertSessionHas('success');

        expect($this->line->refresh()->status->value)->toBe('excluded');
    });
});

describe('reconciling', function (): void {
    beforeEach(function (): void {
        ($this->receipt)('118000.00', '2026-07-01', 'INV-000001');

        $this->post(
            "/banking/accounts/{$this->current->id}/import",
            ['statement' => ($this->statement)("01/07/2026,TFR INV-000001,INV-000001,118000.00\n")],
        );

        $this->line = BankStatementLine::query()->sole();
    });

    it('refuses to complete while there is a difference, and says how much', function (): void {
        $this->post('/banking/reconciliation', [
            'bank_account_id' => $this->current->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'closing_balance' => '118000.00',
            'opening_balance' => '0',
        ])->assertSessionHas('success');

        $reconciliation = BankReconciliation::query()->sole();

        $this->post("/banking/reconciliation/{$reconciliation->id}/complete")
            ->assertSessionHas('error');

        expect(session('error'))->toContain('118000')
            ->and($reconciliation->refresh()->status->value)->toBe('draft');
    });

    it('completes at zero and freezes what it covered', function (): void {
        $journalLineId = (string) DB::table('journal_lines')
            ->where('account_id', ledgerAccount('1020')->id)
            ->where('debit', '>', 0)
            ->value('id');

        $this->post("/banking/transactions/{$this->line->id}/match", [
            'journal_line_id' => $journalLineId,
        ]);

        $this->post('/banking/reconciliation', [
            'bank_account_id' => $this->current->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'closing_balance' => '118000.00',
            'opening_balance' => '0',
        ]);

        $reconciliation = BankReconciliation::query()->sole();

        $this->post("/banking/reconciliation/{$reconciliation->id}/complete")
            ->assertSessionHas('success');

        expect($reconciliation->refresh()->status->value)->toBe('completed');

        // And the line is now a record: unmatching is refused in words.
        $this->post("/banking/transactions/{$this->line->id}/unmatch")
            ->assertSessionHas('error');

        expect(session('error'))->toContain('completed reconciliation')
            ->and($this->line->refresh()->status->value)->toBe('matched');
    });

    it('shows where a difference is coming from', function (): void {
        $this->post('/banking/reconciliation', [
            'bank_account_id' => $this->current->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'closing_balance' => '118000.00',
            'opening_balance' => '0',
        ]);

        $this->get('/banking/reconciliation')
            ->assertInertia(fn (Assert $page) => $page
                ->where('open.figures.difference', '118000.0000')
                ->where('open.figures.unmatched_count', 1)
                ->where('open.figures.unpresented_count', 1),
            );
    });
});

describe('transfers', function (): void {
    it('records one, and shows the entry it posted', function (): void {
        $this->post('/banking/transfers', [
            'from_account_id' => $this->current->id,
            'to_account_id' => $this->petty->id,
            'transfer_date' => '2026-07-05',
            'amount' => '25000.00',
            'reference' => 'Shop float',
        ])->assertSessionHas('success');

        $transfer = BankTransfer::query()->sole();

        expect($transfer->journal_entry_id)->not->toBeNull();

        $this->get('/banking/transfers')
            ->assertInertia(fn (Assert $page) => $page
                ->where('transfers.data.0.number', $transfer->number)
                ->where('transfers.data.0.amount', '25000.0000')
                ->whereNot('transfers.data.0.entry_no', null),
            );
    });

    it('refuses a transfer to the same account at the form', function (): void {
        $this->post('/banking/transfers', [
            'from_account_id' => $this->current->id,
            'to_account_id' => $this->current->id,
            'transfer_date' => '2026-07-05',
            'amount' => '25000.00',
        ])->assertSessionHasErrors('from_account_id');

        expect(BankTransfer::query()->count())->toBe(0);
    });

    it('voids by reversal', function (): void {
        $this->post('/banking/transfers', [
            'from_account_id' => $this->current->id,
            'to_account_id' => $this->petty->id,
            'transfer_date' => '2026-07-05',
            'amount' => '25000.00',
        ]);

        $transfer = BankTransfer::query()->sole();

        $this->post("/banking/transfers/{$transfer->id}/void", ['reason' => 'Never happened'])
            ->assertSessionHas('success');

        expect($transfer->refresh()->voided_at)->not->toBeNull()
            ->and($transfer->void_journal_entry_id)->not->toBeNull()
            // Nothing deleted: the original entry is still there.
            ->and(JournalEntry::query()->count())->toBe(2);
    });
});

describe('authorisation, by role', function (): void {
    it('lets a viewer read and change nothing', function (): void {
        actingAsMember($this->organization, Role::Viewer->value);

        $this->get('/banking/accounts')->assertOk();
        $this->get('/banking/transfers')->assertOk();

        $this->post('/banking/accounts', [
            'account_id' => ledgerAccount('1010')->id,
            'name' => 'Nope',
            'kind' => 'cash',
        ])->assertForbidden();

        $this->post(
            "/banking/accounts/{$this->current->id}/import",
            ['statement' => ($this->statement)("01/07/2026,Anything,,100.00\n")],
        )->assertForbidden();

        expect(BankAccount::query()->count())->toBe(2)
            ->and(BankStatementLine::query()->count())->toBe(0);
    });

    it('lets a bookkeeper import but not match or reconcile', function (): void {
        /*
         * The separation of duties, and the whole reason import has its own
         * permission. Getting the bank's file into the system changes nothing;
         * deciding what a line MEANS is what the books rest on.
         */
        ($this->receipt)('118000.00', '2026-07-01', 'INV-000001');

        $this->post(
            "/banking/accounts/{$this->current->id}/import",
            ['statement' => ($this->statement)("01/07/2026,TFR,INV-000001,118000.00\n")],
        );

        $line = BankStatementLine::query()->sole();

        $journalLineId = (string) DB::table('journal_lines')
            ->where('account_id', ledgerAccount('1020')->id)
            ->where('debit', '>', 0)
            ->value('id');

        actingAsMember($this->organization, Role::Bookkeeper->value);

        // Reading and importing: yes.
        $this->get('/banking/transactions')->assertOk();

        $this->post(
            "/banking/accounts/{$this->current->id}/import",
            ['statement' => ($this->statement)("02/07/2026,Another,,5000.00\n")],
        )->assertSessionHasNoErrors();

        // Deciding what any of it means: no.
        $this->post("/banking/transactions/{$line->id}/match", [
            'journal_line_id' => $journalLineId,
        ])->assertForbidden();

        $this->post("/banking/transactions/{$line->id}/exclude", ['reason' => 'Not ours'])
            ->assertForbidden();

        $this->post('/banking/reconciliation', [
            'bank_account_id' => $this->current->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'closing_balance' => '0',
        ])->assertForbidden();

        expect($line->refresh()->status->value)->toBe('unmatched');

        // And the controls follow the same rule the routes enforce.
        $this->get('/banking/transactions')
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.import', true)
                ->where('can.reconcile', false),
            );
    });

    it('refuses a bookkeeper a transfer, because a transfer posts', function (): void {
        actingAsMember($this->organization, Role::Bookkeeper->value);

        $this->post('/banking/transfers', [
            'from_account_id' => $this->current->id,
            'to_account_id' => $this->petty->id,
            'transfer_date' => '2026-07-05',
            'amount' => '25000.00',
        ])->assertForbidden();

        expect(BankTransfer::query()->count())->toBe(0);

        $this->get('/banking/transfers')
            ->assertInertia(fn (Assert $page) => $page->where('can.create', false));
    });

    it('404s on another organisation\'s reconciliation', function (): void {
        $other = Organization::factory()->create();

        $theirs = app(TenantContext::class)->runAs($other, function () use ($other): BankReconciliation {
            withLedger($other);

            $account = app(SaveBankAccount::class)->handle([
                'account_id' => ledgerAccount('1020')->id,
                'name' => 'Theirs',
                'kind' => 'bank',
            ]);

            return app(StartReconciliation::class)->handle(
                bankAccount: $account,
                periodStart: Carbon::parse('2026-07-01'),
                periodEnd: Carbon::parse('2026-07-31'),
                closingBalance: '0',
                openingBalance: '0',
            );
        });

        actingAsMember($this->organization, Role::Owner->value);

        $this->post("/banking/reconciliation/{$theirs->id}/complete")->assertNotFound();
        $this->delete("/banking/reconciliation/{$theirs->id}")->assertNotFound();
    });
});
