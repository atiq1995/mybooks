<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Enums\EntryStatus;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Exceptions\UnbalancedJournal;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|---------------------------------------------------------------------------
| Posting to the ledger
|---------------------------------------------------------------------------
|
| PostJournalEntry is the only code in the application permitted to write
| journal_entries or journal_lines. Everything it refuses, it must refuse
| here — and the database must refuse the same things independently, which is
| why several of these tests bypass the domain entirely and write raw SQL.
|
| @see ACCOUNTING_RULES.md §1, §4
*/

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization);
    $this->actor = User::factory()->create();
    $this->post = app(PostJournalEntry::class);

    $this->ar = ledgerAccount(SystemAccount::AccountsReceivable);
    $this->gst = ledgerAccount(SystemAccount::GstOutput);
    $this->revenue = Account::query()->where('type', 'income')->where('is_header', false)
        ->whereNull('system_role')->orderBy('code')->firstOrFail();

    // A date inside the open year we just created.
    $this->date = Carbon::parse($this->year->starts_on->toDateString())->addMonth();

    $this->sale = fn (string $net = '100000.0000', string $tax = '17100.0000', ?string $sourceId = null, string $purpose = 'issue'): JournalDraft => JournalDraft::inBaseCurrency(
        date: $this->date,
        currency: 'PKR',
        lines: [
            JournalLineDraft::debit($this->ar->id, bcadd($net, $tax, 4)),
            JournalLineDraft::credit($this->revenue->id, $net),
            JournalLineDraft::credit($this->gst->id, $tax),
        ],
        source: ['invoice', $sourceId, $purpose],
        memo: 'Sale of services',
    );
});

describe('a balanced entry', function (): void {
    it('posts, with lines numbered from one and base amounts equal to the transaction amounts', function (): void {
        $entry = $this->post->handle(($this->sale)(), $this->actor);

        expect($entry->status)->toBe(EntryStatus::Posted);
        expect($entry->entry_no)->toStartWith('JE-');
        expect((string) $entry->total_debit)->toBeDecimal('117100.0000');
        expect((string) $entry->total_credit)->toBeDecimal('117100.0000');
        expect($entry->posted_by)->toBe($this->actor->id);

        $lines = $entry->lines()->orderBy('line_no')->get();

        expect($lines)->toHaveCount(3);
        expect($lines->pluck('line_no')->all())->toBe([1, 2, 3]);
        expect((string) $lines[0]->debit)->toBeDecimal('117100.0000');
        expect((string) $lines[0]->debit_base)->toBeDecimal('117100.0000');
        expect((string) $lines[0]->credit)->toBeDecimal('0.0000');
        expect((string) $lines->sum(fn ($l) => (float) $l->credit))->not->toBe('0');
    });

    it('lands in the fiscal period covering its date', function (): void {
        $entry = $this->post->handle(($this->sale)(), $this->actor);

        $period = FiscalPeriod::query()->findOrFail($entry->fiscal_period_id);

        expect($period->covers($this->date))->toBeTrue();
        expect($period->fiscal_year_id)->toBe($this->year->id);
    });

    it('records an audit entry naming the accounts it touched', function (): void {
        $entry = $this->post->handle(($this->sale)(), $this->actor);

        $audit = AuditLog::query()->where('action', 'journal.posted')->sole();

        expect($audit->auditable_id)->toBe($entry->id);
        expect($audit->user_id)->toBe($this->actor->id);
        expect($audit->new_values['accounts'])->toContain($this->ar->code);
        expect($audit->new_values['lines'])->toBe(3);
    });

    it('numbers entries consecutively with no gaps', function (): void {
        $numbers = collect(range(1, 5))
            ->map(fn (): string => $this->post->handle(($this->sale)(), $this->actor)->entry_no)
            ->all();

        expect($numbers)->toBe(['JE-000001', 'JE-000002', 'JE-000003', 'JE-000004', 'JE-000005']);
    });

    it('converts a foreign-currency entry once, at posting, and stores both sides', function (): void {
        $draft = new JournalDraft(
            date: $this->date,
            currency: 'USD',
            baseCurrency: 'PKR',
            exchangeRate: '278.5000000000',
            lines: [
                JournalLineDraft::debit($this->ar->id, '1000.00'),
                JournalLineDraft::credit($this->revenue->id, '1000.00'),
            ],
            source: ['invoice', null, 'issue'],
        );

        $entry = $this->post->handle($draft, $this->actor);
        $lines = $entry->lines()->orderBy('line_no')->get();

        expect((string) $entry->total_debit)->toBeDecimal('1000.0000');
        expect((string) $entry->total_debit_base)->toBeDecimal('278500.0000');
        expect((string) $lines[0]->debit_base)->toBeDecimal('278500.0000');
        expect((string) $lines[1]->credit_base)->toBeDecimal('278500.0000');
    });
});

describe('an entry the domain refuses', function (): void {
    it('refuses one that does not balance, before writing anything', function (): void {
        $draft = JournalDraft::inBaseCurrency(
            date: $this->date,
            currency: 'PKR',
            lines: [
                JournalLineDraft::debit($this->ar->id, '100.0000'),
                JournalLineDraft::credit($this->revenue->id, '99.9999'),
            ],
            source: ['manual', null, 'issue'],
        );

        expect(fn () => $this->post->handle($draft, $this->actor))
            ->toThrow(UnbalancedJournal::class);

        expect(JournalEntry::query()->count())->toBe(0);
        expect(DB::table('journal_lines')->count())->toBe(0);
    });

    it('refuses an entry in a currency that is not the organisation base', function (): void {
        $draft = new JournalDraft(
            date: $this->date,
            currency: 'USD',
            baseCurrency: 'AED', // not PKR
            exchangeRate: '1',
            lines: [
                JournalLineDraft::debit($this->ar->id, '10'),
                JournalLineDraft::credit($this->revenue->id, '10'),
            ],
            source: ['manual', null, 'issue'],
        );

        expect(fn () => $this->post->handle($draft, $this->actor))
            ->toThrow(PostingRefused::class, 'PKR');
    });

    it('refuses a second posting for the same source and purpose', function (): void {
        $invoiceId = (string) Str::uuid7();

        $this->post->handle(($this->sale)(sourceId: $invoiceId), $this->actor);

        expect(fn () => $this->post->handle(($this->sale)(sourceId: $invoiceId), $this->actor))
            ->toThrow(PostingRefused::class, 'has already posted');

        expect(JournalEntry::query()->count())->toBe(1);
    });

    it('allows the same source to post again for a different purpose', function (): void {
        $invoiceId = (string) Str::uuid7();

        $this->post->handle(($this->sale)(sourceId: $invoiceId, purpose: 'issue'), $this->actor);
        $this->post->handle(($this->sale)(sourceId: $invoiceId, purpose: 'settle'), $this->actor);

        expect(JournalEntry::query()->count())->toBe(2);
    });

    it('refuses a date no fiscal period covers', function (): void {
        $draft = JournalDraft::inBaseCurrency(
            date: Carbon::parse($this->year->starts_on->toDateString())->subYears(3),
            currency: 'PKR',
            lines: [
                JournalLineDraft::debit($this->ar->id, '10'),
                JournalLineDraft::credit($this->revenue->id, '10'),
            ],
            source: ['manual', null, 'issue'],
        );

        expect(fn () => $this->post->handle($draft, $this->actor))
            ->toThrow(PostingRefused::class, 'No fiscal period covers');
    });

    it('refuses a closed period without the override, and accepts it with one', function (): void {
        $period = FiscalPeriod::query()->whereDate('starts_on', '<=', $this->date)
            ->whereDate('ends_on', '>=', $this->date)->sole();

        $period->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        expect(fn () => $this->post->handle(($this->sale)(), $this->actor))
            ->toThrow(PostingRefused::class, 'is closed');

        expect(JournalEntry::query()->count())->toBe(0);

        // With the override the entry posts, and the audit note says so — the
        // point of the permission is that its use is visible afterwards.
        $entry = $this->post->handle(($this->sale)(), $this->actor, allowClosedPeriod: true);

        expect($entry->exists)->toBeTrue();
        expect(AuditLog::query()->where('action', 'journal.posted')->sole()->description)
            ->toContain('CLOSED period');
    });

    it('refuses a locked period even with the override, because locked means filed', function (): void {
        FiscalPeriod::query()->whereDate('starts_on', '<=', $this->date)
            ->whereDate('ends_on', '>=', $this->date)
            ->sole()
            ->forceFill(['status' => 'locked', 'closed_at' => now()])
            ->save();

        expect(fn () => $this->post->handle(($this->sale)(), $this->actor, allowClosedPeriod: true))
            ->toThrow(PostingRefused::class, 'is locked and cannot be reopened');
    });

    it('refuses a heading account, whose subtotal a posting would make meaningless', function (): void {
        $header = Account::query()->where('is_header', true)->orderBy('code')->firstOrFail();

        $draft = JournalDraft::inBaseCurrency(
            date: $this->date,
            currency: 'PKR',
            lines: [
                JournalLineDraft::debit($header->id, '10'),
                JournalLineDraft::credit($this->revenue->id, '10'),
            ],
            source: ['manual', null, 'issue'],
        );

        expect(fn () => $this->post->handle($draft, $this->actor))
            ->toThrow(PostingRefused::class, 'is a heading that groups');
    });

    it('refuses an archived account', function (): void {
        $this->revenue->forceFill(['is_active' => false])->save();

        expect(fn () => $this->post->handle(($this->sale)(), $this->actor))
            ->toThrow(PostingRefused::class, $this->revenue->code);
    });

    it('refuses an account belonging to another organisation', function (): void {
        $other = Organization::factory()->create();
        $foreignAccountId = app(TenantContext::class)->runAs(
            $other,
            function () use ($other): string {
                withLedger($other);

                return ledgerAccount(SystemAccount::AccountsReceivable)->id;
            },
        );

        asOrganization($this->organization);

        $draft = JournalDraft::inBaseCurrency(
            date: $this->date,
            currency: 'PKR',
            lines: [
                JournalLineDraft::debit($foreignAccountId, '10'),
                JournalLineDraft::credit($this->revenue->id, '10'),
            ],
            source: ['manual', null, 'issue'],
        );

        // "Not found", not "belongs to someone else". The tenant scope makes
        // those indistinguishable, which is the point.
        expect(fn () => $this->post->handle($draft, $this->actor))
            ->toThrow(PostingRefused::class, 'does not exist in this organisation');

        expect(JournalEntry::query()->count())->toBe(0);
    });
});

describe('the database, independently of the domain', function (): void {
    /**
     * These bypass PostJournalEntry entirely. If the domain check were ever
     * removed by a refactor, the ledger would still be sound — that is the
     * whole reason the constraints exist as well as the code.
     */
    it('refuses an unbalanced entry written by raw SQL', function (): void {
        $entryId = rawEntry($this->organization, $this->date, '100.0000');

        rawLine($entryId, $this->ar->id, 1, debit: '100.0000');
        rawLine($entryId, $this->revenue->id, 2, credit: '99.0000');

        // The balance trigger is DEFERRABLE INITIALLY DEFERRED, so it would
        // normally fire at COMMIT — which never arrives inside a test
        // transaction. Forcing it immediate is how the check is observed.
        expect(fn () => DB::statement('SET CONSTRAINTS ALL IMMEDIATE'))
            ->toThrow(QueryException::class, 'does not balance');
    });

    it('refuses a line carrying both a debit and a credit', function (): void {
        $entryId = rawEntry($this->organization, $this->date, '100.0000');

        expect(fn () => rawLine($entryId, $this->ar->id, 1, debit: '100.0000', credit: '100.0000'))
            ->toThrow(QueryException::class, 'journal_lines_one_side_only');
    });

    it('refuses a line carrying neither', function (): void {
        $entryId = rawEntry($this->organization, $this->date, '100.0000');

        expect(fn () => rawLine($entryId, $this->ar->id, 1))
            ->toThrow(QueryException::class, 'journal_lines_one_side_only');
    });

    it('refuses a negative amount', function (): void {
        $entryId = rawEntry($this->organization, $this->date, '100.0000');

        expect(fn () => rawLine($entryId, $this->ar->id, 1, debit: '-100.0000'))
            ->toThrow(QueryException::class);
    });

    it('refuses a header whose totals disagree with each other', function (): void {
        expect(fn () => rawEntry($this->organization, $this->date, '100.0000', credit: '90.0000'))
            ->toThrow(QueryException::class, 'journal_entries_balanced');
    });

    it('refuses a header whose totals disagree with its lines', function (): void {
        $entryId = rawEntry($this->organization, $this->date, '100.0000');

        rawLine($entryId, $this->ar->id, 1, debit: '90.0000');
        rawLine($entryId, $this->revenue->id, 2, credit: '90.0000');

        // Balanced against itself, but the header claims 100.
        expect(fn () => DB::statement('SET CONSTRAINTS ALL IMMEDIATE'))
            ->toThrow(QueryException::class, 'disagrees with its lines');
    });

    it('refuses to delete a posted entry or line', function (): void {
        $entry = $this->post->handle(($this->sale)(), $this->actor);

        expect(refused(fn () => DB::table('journal_lines')->where('journal_entry_id', $entry->id)->delete()))
            ->toThrow(QueryException::class, 'append-only');

        expect(refused(fn () => DB::table('journal_entries')->where('id', $entry->id)->delete()))
            ->toThrow(QueryException::class, 'append-only');
    });

    it('refuses to edit a posted amount', function (): void {
        $entry = $this->post->handle(($this->sale)(), $this->actor);

        expect(refused(fn () => DB::table('journal_entries')->where('id', $entry->id)
            ->update(['total_debit' => '1.0000', 'total_credit' => '1.0000'])))
            ->toThrow(QueryException::class, 'cannot be edited');

        expect(refused(fn () => DB::table('journal_lines')->where('journal_entry_id', $entry->id)
            ->update(['memo' => 'tampered'])))
            ->toThrow(QueryException::class, 'cannot be edited');
    });

    it('permits exactly one edit: marking an entry reversed', function (): void {
        $entry = $this->post->handle(($this->sale)(), $this->actor);

        DB::table('journal_entries')->where('id', $entry->id)->update(['status' => 'reversed']);

        expect($entry->fresh()?->status)->toBe(EntryStatus::Reversed);
    });

    it('refuses a second entry with the same number', function (): void {
        $this->post->handle(($this->sale)(), $this->actor);

        expect(fn () => rawEntry($this->organization, $this->date, '10.0000', entryNo: 'JE-000001'))
            ->toThrow(QueryException::class);
    });
});

/**
 * Insert a journal entry header with raw SQL, bypassing every domain check.
 */
function rawEntry(
    Organization $organization,
    Carbon $date,
    string $debit,
    ?string $credit = null,
    ?string $entryNo = null,
): string {
    $id = (string) Str::uuid7();
    $credit ??= $debit;

    DB::table('journal_entries')->insert([
        'id' => $id,
        'organization_id' => $organization->getKey(),
        'entry_no' => $entryNo ?? 'RAW-'.Str::upper(Str::random(8)),
        'entry_date' => $date->toDateString(),
        'fiscal_period_id' => FiscalPeriod::query()
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->sole()
            ->getKey(),
        'source_type' => 'raw',
        'source_id' => null,
        'source_purpose' => 'issue',
        'currency' => 'PKR',
        'base_currency' => 'PKR',
        'exchange_rate' => '1',
        'total_debit' => $debit,
        'total_credit' => $credit,
        'total_debit_base' => $debit,
        'total_credit_base' => $credit,
        'status' => 'posted',
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function rawLine(
    string $entryId,
    string $accountId,
    int $lineNo,
    string $debit = '0.0000',
    string $credit = '0.0000',
): void {
    DB::table('journal_lines')->insert([
        'id' => (string) Str::uuid7(),
        'organization_id' => DB::table('journal_entries')->where('id', $entryId)->value('organization_id'),
        'journal_entry_id' => $entryId,
        'line_no' => $lineNo,
        'account_id' => $accountId,
        'debit' => $debit,
        'credit' => $credit,
        'debit_base' => $debit,
        'credit_base' => $credit,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
