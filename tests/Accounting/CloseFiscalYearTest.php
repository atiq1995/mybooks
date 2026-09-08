<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\CloseFiscalYear;
use App\Domain\Accounting\Actions\CreateFiscalYear;
use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Actions\ReverseJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|---------------------------------------------------------------------------
| The year-end close
|---------------------------------------------------------------------------
|
| Income and expense accounts empty into retained earnings; balance sheet
| accounts carry forward. Two properties matter more than the mechanics:
|
|   - after the close, every temporary account nets to zero for that year, so
|     next year's profit and loss starts from nothing;
|   - the trial balance still balances, because the close is an ordinary
|     balanced journal entry rather than a special case.
|
| @see ACCOUNTING_RULES.md §4.14, §7
*/

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->actor = User::factory()->create();

    $this->close = app(CloseFiscalYear::class);
    $this->post = app(PostJournalEntry::class);

    $this->ar = ledgerAccount(SystemAccount::AccountsReceivable);
    $this->retained = ledgerAccount(SystemAccount::RetainedEarnings);

    $this->revenue = Account::query()->where('type', 'income')->where('is_header', false)
        ->whereNull('system_role')->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('type', 'expense')->where('is_header', false)
        ->whereNull('system_role')->orderBy('code')->firstOrFail();

    $this->inYear = Carbon::parse('2026-09-15');

    /**
     * Post a balanced pair inside the year.
     *
     * $income credits revenue against receivables; $spend debits an expense
     * against receivables. Using one balance sheet account for both sides
     * keeps the fixtures short without affecting what is being tested.
     */
    $this->trade = function (string $income, string $spend, ?Carbon $on = null): void {
        $on ??= $this->inYear;

        if ($income !== '0') {
            $this->post->handle(
                JournalDraft::inBaseCurrency(
                    date: $on,
                    currency: 'PKR',
                    lines: [
                        JournalLineDraft::debit($this->ar->id, $income),
                        JournalLineDraft::credit($this->revenue->id, $income),
                    ],
                    source: ['invoice', (string) Str::uuid7(), 'issue'],
                ),
                $this->actor,
            );
        }

        if ($spend !== '0') {
            $this->post->handle(
                JournalDraft::inBaseCurrency(
                    date: $on,
                    currency: 'PKR',
                    lines: [
                        JournalLineDraft::debit($this->expense->id, $spend),
                        JournalLineDraft::credit($this->ar->id, $spend),
                    ],
                    source: ['bill', (string) Str::uuid7(), 'issue'],
                ),
                $this->actor,
            );
        }
    };
});

/**
 * An account's net movement within the year, as debits minus credits.
 */
function netWithin(string $accountId, string $from, string $to): BigDecimal
{
    /** @var object{debits: string|null, credits: string|null}|null $row */
    $row = DB::table('journal_lines')
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->where('journal_lines.account_id', $accountId)
        ->whereDate('journal_entries.entry_date', '>=', $from)
        ->whereDate('journal_entries.entry_date', '<=', $to)
        ->selectRaw('COALESCE(SUM(journal_lines.debit_base), 0) AS debits')
        ->selectRaw('COALESCE(SUM(journal_lines.credit_base), 0) AS credits')
        ->first();

    return BigDecimal::of((string) ($row->debits ?? '0'))
        ->minus(BigDecimal::of((string) ($row->credits ?? '0')));
}

it('moves a profit to retained earnings and empties the temporary accounts', function (): void {
    // Earned 500,000, spent 300,000 — a profit of 200,000.
    ($this->trade)('500000.0000', '300000.0000');

    $result = $this->close->handle($this->year, $this->actor);

    expect($result['net_result'])->toBe('200000.0000')
        ->and($result['accounts_closed'])->toBe(2);

    $from = $this->year->starts_on->toDateString();
    $to = $this->year->ends_on->toDateString();

    // Both temporary accounts net to nothing for the year, which is what makes
    // next year's profit and loss start from zero.
    expect(netWithin($this->revenue->id, $from, $to)->isZero())->toBeTrue()
        ->and(netWithin($this->expense->id, $from, $to)->isZero())->toBeTrue();

    // Equity holds the result. Retained earnings is credit-normal, so a
    // profit leaves it with a credit balance — a negative debits-minus-credits.
    expect((string) netWithin($this->retained->id, $from, $to))->toBe('-200000.0000');

    // The balance sheet account is untouched by the close: 500,000 in,
    // 300,000 out.
    expect((string) netWithin($this->ar->id, $from, $to))->toBe('200000.0000');
});

it('moves a loss the other way', function (): void {
    ($this->trade)('100000.0000', '175000.0000');

    $result = $this->close->handle($this->year, $this->actor);

    expect($result['net_result'])->toBe('-75000.0000');

    // A loss DEBITS retained earnings, reducing equity.
    $line = JournalEntry::query()
        ->where('source_type', 'closing')
        ->sole()
        ->lines()
        ->where('account_id', $this->retained->id)
        ->sole();

    expect((string) $line->debit)->toBeDecimal('75000.0000')
        ->and((string) $line->credit)->toBeDecimal('0.0000');
});

it('leaves the trial balance balanced', function (): void {
    ($this->trade)('500000.0000', '300000.0000');

    $this->close->handle($this->year, $this->actor);

    /** @var object{debits: string, credits: string} $totals */
    $totals = DB::selectOne(
        'SELECT COALESCE(SUM(debit_base), 0) AS debits, COALESCE(SUM(credit_base), 0) AS credits
           FROM journal_lines',
    );

    expect(BigDecimal::of($totals->debits)->isEqualTo(BigDecimal::of($totals->credits)))->toBeTrue();

    $this->artisan('my-books:verify-ledger')->assertExitCode(0);
});

it('dates the closing entry on the last day of the year it closes', function (): void {
    ($this->trade)('1000.0000', '400.0000');

    $result = $this->close->handle($this->year, $this->actor);

    // Not today, and not in the next year: the profit and loss for the year
    // being closed still has to show the trading it summarises.
    expect($result['entry']?->entry_date->toDateString())
        ->toBe($this->year->ends_on->toDateString());
});

it('closes every period of the year, but does not lock them', function (): void {
    ($this->trade)('1000.0000', '400.0000');

    $this->close->handle($this->year, $this->actor);

    $statuses = FiscalPeriod::query()
        ->where('fiscal_year_id', $this->year->getKey())
        ->pluck('status')
        ->unique()
        ->values();

    expect($statuses->all())->toBe([PeriodStatus::Closed]);

    // Closed, not locked: a correction may still be needed before filing, and
    // locking is what filing means.
    expect($this->year->fresh()?->status)->toBe(PeriodStatus::Closed);
});

it('records the closing entry against the year', function (): void {
    ($this->trade)('1000.0000', '400.0000');

    $result = $this->close->handle($this->year, $this->actor);

    expect($this->year->fresh()?->closing_entry_id)->toBe($result['entry']?->id)
        ->and($this->year->fresh()?->closingEntry?->entry_no)->toBe($result['entry']?->entry_no);
});

it('closes a year in which nothing was posted, without an entry', function (): void {
    $result = $this->close->handle($this->year, $this->actor);

    expect($result['entry'])->toBeNull()
        ->and($result['net_result'])->toBe('0')
        ->and($result['accounts_closed'])->toBe(0)
        ->and($this->year->fresh()?->status)->toBe(PeriodStatus::Closed)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('refuses to close a year twice', function (): void {
    ($this->trade)('1000.0000', '400.0000');

    $this->close->handle($this->year, $this->actor);

    expect(fn () => $this->close->handle($this->year->fresh(), $this->actor))
        ->toThrow(PostingRefused::class, 'already closed');

    expect(JournalEntry::query()->where('source_type', 'closing')->count())->toBe(1);
});

it('closes only the year it is asked to close', function (): void {
    // Trading in both years, then closing only the first.
    ($this->trade)('500000.0000', '300000.0000');

    $next = app(CreateFiscalYear::class)->handle($this->organization, 2027, $this->actor);

    ($this->trade)('90000.0000', '20000.0000', Carbon::parse('2027-09-15'));

    $result = $this->close->handle($this->year, $this->actor);

    // The second year's trading is untouched by the first year's close.
    expect($result['net_result'])->toBe('200000.0000');

    expect((string) netWithin(
        $this->revenue->id,
        $next->starts_on->toDateString(),
        $next->ends_on->toDateString(),
    ))->toBe('-90000.0000');

    expect($next->fresh()?->status)->toBe(PeriodStatus::Open);
});

it('can be undone by reversing the closing entry', function (): void {
    ($this->trade)('500000.0000', '300000.0000');

    $result = $this->close->handle($this->year, $this->actor);
    $entry = $result['entry'];

    expect($entry)->not->toBeNull();

    /*
     * The close is an ordinary balanced entry, so a premature close is undone
     * the same way any other mistake is — with one extra step, because the
     * close shut every period of the year. Reopening the final period is what
     * an accountant would actually do, and it is the whole reason `closed` is
     * distinct from `locked`.
     */
    $final = FiscalPeriod::query()
        ->where('fiscal_year_id', $this->year->getKey())
        ->where('sequence', 12)
        ->sole();

    $final->forceFill(['status' => PeriodStatus::Open, 'closed_at' => null])->save();

    $reversal = app(ReverseJournalEntry::class)->handle(
        entry: $entry,
        actor: $this->actor,
        date: Carbon::parse($this->year->ends_on->toDateString()),
        reason: 'Closed a month early',
    );

    expect($reversal->reverses_entry_id)->toBe($entry?->id);

    $this->artisan('my-books:verify-ledger')->assertExitCode(0);
});

it('audits the close with the net result', function (): void {
    ($this->trade)('500000.0000', '300000.0000');

    $this->close->handle($this->year, $this->actor);

    $audit = AuditLog::query()->where('action', 'accounting.year_closed')->sole();

    expect($audit->new_values['net_result'])->toBe('200000.0000')
        ->and($audit->new_values['accounts_closed'])->toBe(2)
        ->and($audit->new_values['label'])->toBe('2026-27')
        ->and($audit->user_id)->toBe($this->actor->id);
});

it('leaves another organisation untouched', function (): void {
    ($this->trade)('500000.0000', '300000.0000');

    $other = Organization::factory()->create();

    $theirYear = app(TenantContext::class)->runAs(
        $other,
        fn () => withLedger($other, 2026),
    );

    asOrganization($this->organization);

    $this->close->handle($this->year, $this->actor);

    expect($theirYear->fresh()?->status)->toBe(PeriodStatus::Open);

    app(TenantContext::class)->runAs($other, function (): void {
        expect(FiscalPeriod::query()->where('status', PeriodStatus::Closed->value)->count())->toBe(0);
    });
});
