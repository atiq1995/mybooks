<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Actions\ReverseJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ledger, under the RUNTIME database role.
 *
 * The rest of the suite connects as the schema owner, which bypasses
 * row-level security so fixtures can be built without tenant context. That
 * trade has hidden real bugs on this project more than once: the two
 * isolation layers can disagree, and only traffic that is subject to both
 * shows it.
 *
 * So the ledger — the tables where a leak would be most expensive — is
 * driven once here as `my_books_app`, with the domain Actions doing the
 * writing rather than raw SQL, because it is the Actions that production
 * runs.
 *
 * @see RowLevelSecurityTest for the same treatment of organisations and audit
 */
beforeEach(function (): void {
    $this->tenant = app(TenantContext::class);

    $this->alpha = Organization::factory()->create(['name' => 'Alpha']);
    $this->beta = Organization::factory()->create(['name' => 'Beta']);

    $this->actor = User::factory()->create();

    // Both ledgers built as the owner. What is being tested is what the app
    // role can then see and do, not the building.
    foreach ([$this->alpha, $this->beta] as $organization) {
        $this->tenant->runAs($organization, function () use ($organization): void {
            withLedger($organization);
        });
    }

    DB::statement('SET ROLE my_books_app');
});

afterEach(function (): void {
    DB::statement("SELECT set_config('app.organization_id', '', false), set_config('app.user_id', '', false)");
    DB::statement('RESET ROLE');
});

/**
 * Post a sale in one organisation, as the app role would.
 */
function postAs(Organization $organization, User $actor): JournalEntry
{
    return app(TenantContext::class)->runAs($organization, function () use ($actor): JournalEntry {
        $ar = Account::query()->where('system_role', SystemAccount::AccountsReceivable->value)->sole();
        $gst = Account::query()->where('system_role', SystemAccount::GstOutput->value)->sole();
        $revenue = Account::query()->where('type', 'income')->where('is_header', false)
            ->whereNull('system_role')->orderBy('code')->firstOrFail();

        return app(PostJournalEntry::class)->handle(
            JournalDraft::inBaseCurrency(
                date: now(),
                currency: 'PKR',
                lines: [
                    JournalLineDraft::debit($ar->id, '117100.0000'),
                    JournalLineDraft::credit($revenue->id, '100000.0000'),
                    JournalLineDraft::credit($gst->id, '17100.0000'),
                ],
                source: ['invoice', (string) Str::uuid7(), 'issue'],
                memo: 'Taxable sale',
            ),
            $actor,
        );
    });
}

it('posts a whole taxable sale as the application role', function (): void {
    // The Actions do the writing, so this covers the RLS WITH CHECK clauses on
    // journal_entries, journal_lines, document_sequences and audit_logs at once
    // — a missing one refuses the write outright, as happened with
    // `organizations` in Phase 1.
    $entry = postAs($this->alpha, $this->actor);

    expect($entry->exists)->toBeTrue()
        ->and((string) $entry->total_debit)->toBeDecimal('117100.0000');

    $this->tenant->runAs($this->alpha, function () use ($entry): void {
        expect($entry->lines()->count())->toBe(3);
    });
});

it('shows an organisation only its own entries, and none without context', function (): void {
    postAs($this->alpha, $this->actor);
    postAs($this->beta, $this->actor);

    // No context: the ledger is invisible, not merely filtered.
    expect(DB::table('journal_entries')->count())->toBe(0)
        ->and(DB::table('journal_lines')->count())->toBe(0)
        ->and(DB::table('accounts')->count())->toBe(0);

    $this->tenant->runAs($this->alpha, function (): void {
        expect(DB::table('journal_entries')->count())->toBe(1)
            ->and(DB::table('journal_lines')->count())->toBe(3);

        /*
         * With the Eloquent scope explicitly lifted, so nothing but the
         * database is standing between this query and the other
         * organisation's ledger.
         */
        $bleed = $this->tenant->runUnscoped(
            fn (): int => DB::table('journal_entries')
                ->where('organization_id', $this->beta->getKey())
                ->count(),
        );

        expect($bleed)->toBe(0);
    });
});

it('refuses an entry written into another organisation', function (): void {
    $this->tenant->runAs($this->alpha, function (): void {
        $period = $this->tenant->runUnscoped(
            fn (): string => (string) DB::table('fiscal_periods')
                ->where('organization_id', $this->beta->getKey())
                ->value('id'),
        );

        expect(refused(fn () => DB::table('journal_entries')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->beta->getKey(),
            'entry_no' => 'CROSS-1',
            'entry_date' => now()->toDateString(),
            'fiscal_period_id' => $period,
            'source_type' => 'cross_tenant',
            'source_purpose' => 'issue',
            'currency' => 'PKR',
            'base_currency' => 'PKR',
            'exchange_rate' => '1',
            'total_debit' => '1.0000',
            'total_credit' => '1.0000',
            'total_debit_base' => '1.0000',
            'total_credit_base' => '1.0000',
            'status' => 'posted',
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ])))->toThrow(QueryException::class);
    });
});

it('refuses an account written into another organisation', function (): void {
    $this->tenant->runAs($this->alpha, function (): void {
        expect(refused(fn () => DB::table('accounts')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->beta->getKey(),
            'code' => 'CROSS',
            'name' => 'Planted in the wrong books',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'is_active' => true,
            'is_header' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ])))->toThrow(QueryException::class);
    });
});

it('keeps the ledger append-only for the application role too', function (): void {
    $entry = postAs($this->alpha, $this->actor);

    $this->tenant->runAs($this->alpha, function () use ($entry): void {
        foreach ([
            fn () => DB::table('journal_entries')->where('id', $entry->id)
                ->update(['total_debit' => '1.0000', 'total_credit' => '1.0000']),
            fn () => DB::table('journal_entries')->where('id', $entry->id)->delete(),
            fn () => DB::table('journal_lines')->where('journal_entry_id', $entry->id)->delete(),
            fn () => DB::table('journal_lines')->where('journal_entry_id', $entry->id)
                ->update(['memo' => 'tampered']),
        ] as $attempt) {
            expect(refused($attempt))->toThrow(QueryException::class);
        }
    });
});

it('reverses an entry as the application role, netting every account to zero', function (): void {
    $entry = postAs($this->alpha, $this->actor);

    $this->tenant->runAs($this->alpha, function () use ($entry): void {
        $reversal = app(ReverseJournalEntry::class)->handle($entry, $this->actor);

        expect($reversal->reverses_entry_id)->toBe($entry->id);

        $nets = DB::table('journal_lines')
            ->selectRaw('account_id, SUM(debit_base) - SUM(credit_base) AS net')
            ->groupBy('account_id')
            ->pluck('net', 'account_id');

        expect($nets)->toHaveCount(3);

        foreach ($nets as $accountId => $net) {
            expect(BigDecimal::of((string) $net)->isZero())
                ->toBeTrue("Account {$accountId} did not net to zero: {$net}");
        }
    });
});

it('numbers each organisation independently, as the application role', function (): void {
    // Both start at 1. A sequence visible across organisations would mean one
    // tenant's activity leaked information about another's volume.
    expect(postAs($this->alpha, $this->actor)->entry_no)->toBe('JE-000001')
        ->and(postAs($this->beta, $this->actor)->entry_no)->toBe('JE-000001')
        ->and(postAs($this->alpha, $this->actor)->entry_no)->toBe('JE-000002');
});

it('verifies the ledger per organisation, as the application role', function (): void {
    postAs($this->alpha, $this->actor);
    postAs($this->beta, $this->actor);

    // The verifier now scopes every check explicitly rather than relying on
    // RLS, so it reaches the same answer whichever role runs it.
    $this->artisan('my-books:verify-ledger')->assertExitCode(0);
});
