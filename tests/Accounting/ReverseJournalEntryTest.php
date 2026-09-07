<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Actions\ReverseJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Enums\EntryStatus;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|---------------------------------------------------------------------------
| Correcting the ledger
|---------------------------------------------------------------------------
|
| The ledger is append-only, so a mistake is corrected by posting its mirror
| image. Both entries remain visible for ever: the error, the correction, and
| the order they happened in. The test that matters most is exactness — a
| reversal that does not net to zero is worse than no reversal at all.
|
| @see ACCOUNTING_RULES.md §4.15, I4
*/

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization);
    $this->actor = User::factory()->create();

    $this->post = app(PostJournalEntry::class);
    $this->reverse = app(ReverseJournalEntry::class);

    $this->ar = ledgerAccount(SystemAccount::AccountsReceivable);
    $this->gst = ledgerAccount(SystemAccount::GstOutput);
    $this->revenue = Account::query()->where('type', 'income')->where('is_header', false)
        ->whereNull('system_role')->orderBy('code')->firstOrFail();

    $this->date = Carbon::parse($this->year->starts_on->toDateString())->addMonth();

    $this->entry = $this->post->handle(
        JournalDraft::inBaseCurrency(
            date: $this->date,
            currency: 'PKR',
            lines: [
                JournalLineDraft::debit($this->ar->id, '117100.0000'),
                JournalLineDraft::credit($this->revenue->id, '100000.0000'),
                JournalLineDraft::credit($this->gst->id, '17100.0000'),
            ],
            source: ['invoice', null, 'issue'],
        ),
        $this->actor,
    );
});

it('posts a mirror image that nets the ledger back to zero, account by account', function (): void {
    $reversal = $this->reverse->handle($this->entry, $this->actor);

    expect($reversal->reverses_entry_id)->toBe($this->entry->id);
    expect($reversal->isReversal())->toBeTrue();

    // Every account touched must net to exactly zero. Anything else means the
    // reversal changed a balance rather than undoing one.
    $netByAccount = DB::table('journal_lines')
        ->selectRaw('account_id, SUM(debit_base) - SUM(credit_base) AS net')
        ->groupBy('account_id')
        ->pluck('net', 'account_id');

    expect($netByAccount)->toHaveCount(3);

    foreach ($netByAccount as $accountId => $net) {
        expect(BigDecimal::of((string) $net)->isZero())
            ->toBeTrue("Account {$accountId} did not net to zero: {$net}");
    }
});

it('swaps every side, keeping each amount identical', function (): void {
    $reversal = $this->reverse->handle($this->entry, $this->actor);

    $original = $this->entry->lines()->orderBy('line_no')->get()->keyBy('account_id');
    $mirrored = $reversal->lines()->orderBy('line_no')->get()->keyBy('account_id');

    foreach ($original as $accountId => $line) {
        $mirror = $mirrored[$accountId];

        expect((string) $mirror->credit)->toBeDecimal((string) $line->debit);
        expect((string) $mirror->debit)->toBeDecimal((string) $line->credit);
        expect((string) $mirror->credit_base)->toBeDecimal((string) $line->debit_base);
        expect((string) $mirror->debit_base)->toBeDecimal((string) $line->credit_base);
    }
});

it('marks the original reversed while leaving it otherwise untouched', function (): void {
    $before = [
        'entry_no' => $this->entry->entry_no,
        'entry_date' => $this->entry->entry_date->toDateString(),
        'total_debit' => (string) $this->entry->total_debit,
        'total_credit' => (string) $this->entry->total_credit,
    ];

    $this->reverse->handle($this->entry, $this->actor);

    $original = $this->entry->fresh();

    expect($original?->status)->toBe(EntryStatus::Reversed);
    expect([
        'entry_no' => $original?->entry_no,
        'entry_date' => $original?->entry_date->toDateString(),
        'total_debit' => (string) $original?->total_debit,
        'total_credit' => (string) $original?->total_credit,
    ])->toBe($before);
    expect($original?->lines()->count())->toBe(3);
});

it('dates the reversal today by default, not on the original date', function (): void {
    // Back-dating a correction would change figures that have already been
    // reported.
    $reversal = $this->reverse->handle($this->entry, $this->actor);

    expect($reversal->entry_date->toDateString())->toBe(Carbon::now()->toDateString());
    expect($reversal->entry_date->toDateString())->not->toBe($this->entry->entry_date->toDateString());
});

it('accepts an explicit reversal date inside an open period', function (): void {
    $on = Carbon::parse($this->year->starts_on->toDateString())->addMonths(2);

    $reversal = $this->reverse->handle($this->entry, $this->actor, date: $on);

    expect($reversal->entry_date->toDateString())->toBe($on->toDateString());
});

it('keeps the original exchange rate rather than today\'s', function (): void {
    $foreign = $this->post->handle(
        new JournalDraft(
            date: $this->date,
            currency: 'USD',
            baseCurrency: 'PKR',
            exchangeRate: '278.5000000000',
            lines: [
                JournalLineDraft::debit($this->ar->id, '1000.00'),
                JournalLineDraft::credit($this->revenue->id, '1000.00'),
            ],
            source: ['invoice', null, 'issue'],
        ),
        $this->actor,
    );

    $reversal = $this->reverse->handle($foreign, $this->actor);

    expect((string) $reversal->exchange_rate)->toBe((string) $foreign->exchange_rate);
    expect((string) $reversal->total_debit_base)->toBeDecimal('278500.0000');
});

it('refuses to reverse the same entry twice', function (): void {
    $this->reverse->handle($this->entry, $this->actor);

    expect(fn () => $this->reverse->handle($this->entry->fresh(), $this->actor))
        ->toThrow(PostingRefused::class, 'already been reversed');
});

it('refuses to reverse a reversal, which would restore the original error', function (): void {
    $reversal = $this->reverse->handle($this->entry, $this->actor);

    expect(fn () => $this->reverse->handle($reversal, $this->actor))
        ->toThrow(PostingRefused::class, 'is itself a reversal');
});

it('carries a reason into the memo when one is given', function (): void {
    $reversal = $this->reverse->handle(
        $this->entry,
        $this->actor,
        reason: 'Customer cancelled before delivery',
    );

    expect($reversal->memo)->toBe('Customer cancelled before delivery');
});

it('names the original in the memo when no reason is given', function (): void {
    $reversal = $this->reverse->handle($this->entry, $this->actor);

    expect($reversal->memo)->toContain($this->entry->entry_no);
});
