<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|---------------------------------------------------------------------------
| The third balance check
|---------------------------------------------------------------------------
|
| The verifier re-derives every invariant from raw journal lines. Its value is
| entirely in what it catches, so each test here deliberately corrupts the
| ledger — with triggers disabled, the way a hand-written SQL fix at 2am would
| — and asserts the command notices and exits non-zero.
|
| A verifier that has never been shown a corrupt ledger is not a verifier.
|
| @see ACCOUNTING_RULES.md §1, §10
*/

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization);
    $this->actor = User::factory()->create();

    $ar = ledgerAccount(SystemAccount::AccountsReceivable);
    $revenue = Account::query()->where('type', 'income')->where('is_header', false)
        ->whereNull('system_role')->orderBy('code')->firstOrFail();

    $this->entry = app(PostJournalEntry::class)->handle(
        JournalDraft::inBaseCurrency(
            date: Carbon::parse($this->year->starts_on->toDateString())->addMonth(),
            currency: 'PKR',
            lines: [
                JournalLineDraft::debit($ar->id, '50000.0000'),
                JournalLineDraft::credit($revenue->id, '50000.0000'),
            ],
            source: ['invoice', null, 'issue'],
        ),
        $this->actor,
    );
});

/**
 * Corrupt the ledger the way a direct SQL fix would: triggers off, one value
 * changed, triggers back on. Nothing in the application can do this — which is
 * exactly why the verifier has to.
 */
function withTriggersDisabled(Closure $callback): void
{
    /*
     * The balance check is a deferred constraint trigger, so the entry posted
     * in beforeEach leaves a pending trigger event and PostgreSQL refuses to
     * ALTER a table that has one. Forcing constraints immediate settles them
     * — and incidentally proves the posted entry was sound before we corrupt
     * it.
     */
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

    DB::unprepared('ALTER TABLE journal_lines DISABLE TRIGGER USER');
    DB::unprepared('ALTER TABLE journal_entries DISABLE TRIGGER USER');

    try {
        $callback();
    } finally {
        DB::unprepared('ALTER TABLE journal_lines ENABLE TRIGGER USER');
        DB::unprepared('ALTER TABLE journal_entries ENABLE TRIGGER USER');
    }
}

it('passes on a ledger posted entirely through the domain', function (): void {
    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('Every invariant holds')
        ->assertExitCode(0);
});

it('passes when there is nothing posted at all', function (): void {
    withTriggersDisabled(function (): void {
        DB::table('journal_lines')->delete();
        DB::table('journal_entries')->delete();
    });

    $this->artisan('my-books:verify-ledger')->assertExitCode(0);
});

it('catches an entry whose lines no longer balance', function (): void {
    withTriggersDisabled(function (): void {
        DB::table('journal_lines')
            ->where('journal_entry_id', $this->entry->id)
            ->where('debit', '>', 0)
            ->update(['debit' => '49999.0000', 'debit_base' => '49999.0000']);
    });

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('entry_balances')
        ->assertExitCode(1);
});

it('catches a header that disagrees with its lines', function (): void {
    withTriggersDisabled(function (): void {
        DB::table('journal_entries')->where('id', $this->entry->id)->update([
            'total_debit' => '60000.0000',
            'total_credit' => '60000.0000',
        ]);
    });

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('header_matches_lines')
        ->assertExitCode(1);
});

it('catches a line with a debit and a credit at once', function (): void {
    withTriggersDisabled(function (): void {
        DB::unprepared(
            'ALTER TABLE journal_lines DROP CONSTRAINT journal_lines_one_side_only'
        );

        DB::table('journal_lines')
            ->where('journal_entry_id', $this->entry->id)
            ->where('debit', '>', 0)
            ->update(['credit' => '50000.0000']);
    });

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('line_sides')
        ->assertExitCode(1);
});

it('catches a trial balance that does not balance', function (): void {
    /*
     * The single most important number in the system. A one-sided line makes
     * the whole ledger's debits and credits disagree, which is the state that
     * stops a balance sheet from balancing.
     */
    withTriggersDisabled(function (): void {
        DB::table('journal_lines')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->organization->getKey(),
            'journal_entry_id' => $this->entry->id,
            'line_no' => 99,
            'account_id' => ledgerAccount(SystemAccount::AccountsReceivable)->id,
            'debit' => '1.0000',
            'credit' => '0.0000',
            'debit_base' => '1.0000',
            'credit_base' => '0.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('trial_balance')
        ->assertExitCode(1);
});

it('catches a line whose organisation differs from its entry', function (): void {
    $other = Organization::factory()->create();

    withTriggersDisabled(function () use ($other): void {
        DB::table('journal_lines')
            ->where('journal_entry_id', $this->entry->id)
            ->limit(1)
            ->update(['organization_id' => $other->getKey()]);
    });

    $this->artisan('my-books:verify-ledger')
        ->expectsOutputToContain('cross_tenant_lines')
        ->assertExitCode(1);
});

it('reports machine-readable output when asked', function (): void {
    $this->artisan('my-books:verify-ledger --json')->assertExitCode(0);

    withTriggersDisabled(function (): void {
        DB::table('journal_entries')->where('id', $this->entry->id)->update([
            'total_debit' => '1.0000',
            'total_credit' => '1.0000',
        ]);
    });

    $this->artisan('my-books:verify-ledger --json')
        ->expectsOutputToContain('"status": "discrepancies"')
        ->assertExitCode(1);
});

it('can verify a single organisation by slug', function (): void {
    $untouched = Organization::factory()->create();

    withTriggersDisabled(function (): void {
        DB::table('journal_entries')->where('id', $this->entry->id)->update([
            'total_debit' => '1.0000',
            'total_credit' => '1.0000',
        ]);
    });

    // The clean organisation passes on its own...
    $this->artisan('my-books:verify-ledger', ['--organization' => $untouched->slug])
        ->assertExitCode(0);

    // ...while the corrupted one still fails.
    $this->artisan('my-books:verify-ledger', ['--organization' => $this->organization->slug])
        ->assertExitCode(1);
});
