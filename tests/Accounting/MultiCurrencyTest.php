<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Actions\RecordExchangeRate;
use App\Domain\Accounting\Actions\RevalueForeignCurrencyBalances;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\MissingExchangeRate;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\ExchangeRate;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\ExchangeRateService;
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
| Multi-currency
|---------------------------------------------------------------------------
|
| Two rules do the work here, and everything else follows from them:
|
|   - the rate used is the latest ON OR BEFORE the date, never the newest
|     rate available. Otherwise entering today's rate would restate every
|     historical document.
|   - a base amount is computed once at posting and NEVER recomputed. That is
|     what makes historical figures stable, and what makes a realised gain
|     computable at settlement.
|
| Revaluation is the exception that proves the second rule: it does not touch
| the original lines, it posts an adjustment — and reverses it the next day,
| because an unrealised gain belongs only to the period that reported it.
|
| @see ACCOUNTING_RULES.md §8
*/

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->actor = User::factory()->create();

    $this->rates = app(RecordExchangeRate::class);
    $this->service = app(ExchangeRateService::class);
    $this->revalue = app(RevalueForeignCurrencyBalances::class);
    $this->post = app(PostJournalEntry::class);

    $this->fx = ledgerAccount(SystemAccount::FxGainLoss);
    $this->revenue = Account::query()->where('type', 'income')->where('is_header', false)
        ->whereNull('system_role')->orderBy('code')->firstOrFail();

    /** A bank account denominated in USD, which is what gets revalued. */
    $this->usdBank = tap(new Account)->forceFill([
        'id' => (string) Str::uuid7(),
        'organization_id' => $this->organization->getKey(),
        'code' => '1015',
        'name' => 'Bank — USD',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'currency' => 'USD',
        'is_active' => true,
        'is_header' => false,
    ]);

    $this->usdBank->save();

    $this->record = fn (string $rate, string $on): ExchangeRate => $this->rates->handle(
        from: 'USD',
        to: 'PKR',
        rate: $rate,
        effectiveOn: Carbon::parse($on),
        actor: $this->actor,
    );

    /** Receive USD into the USD bank account at a given rate. */
    $this->receive = function (string $usd, string $rate, string $on): JournalEntry {
        $base = (string) BigDecimal::of($usd)->multipliedBy(BigDecimal::of($rate))->toScale(4);

        return $this->post->handle(
            new JournalDraft(
                date: Carbon::parse($on),
                currency: 'USD',
                baseCurrency: 'PKR',
                exchangeRate: $rate,
                lines: [
                    JournalLineDraft::debit($this->usdBank->id, $usd),
                    JournalLineDraft::credit($this->revenue->id, $usd),
                ],
                source: ['invoice', (string) Str::uuid7(), 'issue'],
                memo: "Received {$usd} USD at {$rate} (PKR {$base})",
            ),
            $this->actor,
        );
    };
});

describe('finding the rate', function (): void {
    it('uses the latest rate on or before the date, not the newest one', function (): void {
        ($this->record)('275.0000000000', '2026-08-01');
        ($this->record)('280.0000000000', '2026-09-01');
        ($this->record)('290.0000000000', '2026-10-01');

        // The whole point: a September document converts at September's rate
        // even after October's rate is known.
        expect($this->service->rate('USD', 'PKR', Carbon::parse('2026-09-15')))
            ->toBe('280.0000000000');

        expect($this->service->rate('USD', 'PKR', Carbon::parse('2026-08-31')))
            ->toBe('275.0000000000');

        // On the day it takes effect, inclusive.
        expect($this->service->rate('USD', 'PKR', Carbon::parse('2026-09-01')))
            ->toBe('280.0000000000');
    });

    it('converts a currency to itself at one, without a stored rate', function (): void {
        expect($this->service->rate('PKR', 'PKR'))->toBe('1')
            ->and(ExchangeRate::query()->count())->toBe(0);
    });

    it('derives the inverse when only one direction was recorded', function (): void {
        ($this->record)('280.0000000000', '2026-09-01');

        // Somebody who says "1 USD = 280 PKR" has answered both directions.
        // Asking them to enter both invites the two drifting apart.
        expect($this->service->rate('PKR', 'USD', Carbon::parse('2026-09-15')))
            ->toBe('0.0035714286');
    });

    it('refuses to guess a rate it does not have', function (): void {
        ($this->record)('280.0000000000', '2026-09-01');

        // Before any rate exists.
        expect(fn () => $this->service->rate('USD', 'PKR', Carbon::parse('2026-08-01')))
            ->toThrow(MissingExchangeRate::class, 'cannot be guessed');

        // A pair nobody has recorded.
        expect(fn () => $this->service->rate('GBP', 'PKR', Carbon::parse('2026-09-15')))
            ->toThrow(MissingExchangeRate::class, 'GBP to PKR');
    });

    it('converts an amount, rounding once at the end', function (): void {
        ($this->record)('278.5000000000', '2026-09-01');

        expect($this->service->convert('1000.00', 'USD', 'PKR', Carbon::parse('2026-09-15')))
            ->toBeDecimal('278500.0000');

        // Three thirds of a dollar: rounding each would drift, rounding once
        // does not.
        expect($this->service->convert('0.3333', 'USD', 'PKR', Carbon::parse('2026-09-15')))
            ->toBeDecimal('92.8241');
    });
});

describe('recording a rate', function (): void {
    it('records one rate per pair per day, correcting rather than duplicating', function (): void {
        ($this->record)('280.0000000000', '2026-09-01');
        ($this->record)('281.5000000000', '2026-09-01');

        // Two rows for one day would let two people get different answers
        // from the same invoice.
        expect(ExchangeRate::query()->count())->toBe(1)
            ->and(ExchangeRate::query()->sole()->rate)->toBe('281.5000000000');

        expect(AuditLog::query()->where('action', 'accounting.rate_corrected')->sole()->old_values)
            ->toBe(['rate' => '280.0000000000']);
    });

    it('does not restate anything already posted when a rate is corrected', function (): void {
        ($this->record)('278.5000000000', '2026-09-01');

        $entry = ($this->receive)('1000.00', '278.5000000000', '2026-09-15');

        ($this->record)('290.0000000000', '2026-09-01');

        // The base amount was computed once, at posting, and is never
        // recomputed. This is the invariant that makes history stable.
        expect((string) $entry->fresh()?->total_debit_base)->toBeDecimal('278500.0000');
    });

    it('keeps each organisation rates to itself', function (): void {
        ($this->record)('280.0000000000', '2026-09-01');

        $other = Organization::factory()->create();

        app(TenantContext::class)->runAs($other, function (): void {
            expect(ExchangeRate::query()->count())->toBe(0);

            // And an organisation without the rate cannot borrow it.
            expect(fn () => $this->service->rate('USD', 'PKR', Carbon::parse('2026-09-15')))
                ->toThrow(MissingExchangeRate::class);
        });
    });

    it('audits what was recorded', function (): void {
        ($this->record)('280.0000000000', '2026-09-01');

        $audit = AuditLog::query()->where('action', 'accounting.rate_recorded')->sole();

        expect($audit->new_values['rate'])->toBe('280.0000000000')
            ->and($audit->new_values['from_currency'])->toBe('USD')
            ->and($audit->new_values['source'])->toBe('manual')
            ->and($audit->user_id)->toBe($this->actor->id);
    });
});

describe('period-end revaluation', function (): void {
    it('previews the adjustment without posting anything', function (): void {
        ($this->record)('278.5000000000', '2026-09-01');
        ($this->receive)('1000.00', '278.5000000000', '2026-09-15');

        // The rate rose by the month end: the USD held is worth more PKR.
        ($this->record)('285.0000000000', '2026-09-30');

        $adjustments = $this->revalue->adjustmentsAt(Carbon::parse('2026-09-30'), 'PKR');

        expect($adjustments)->toHaveCount(1);

        $adjustment = $adjustments[0];

        expect($adjustment['code'])->toBe('1015')
            ->and($adjustment['foreign_balance'])->toBe('1000.0000')
            ->and($adjustment['carrying_value'])->toBe('278500.0000')
            ->and($adjustment['revalued_to'])->toBe('285000.0000')
            ->and($adjustment['difference'])->toBe('6500.0000');

        // A preview posts nothing.
        expect(JournalEntry::query()->count())->toBe(1);
    });

    it('posts the gain and reverses it the next day', function (): void {
        ($this->record)('278.5000000000', '2026-09-01');
        ($this->receive)('1000.00', '278.5000000000', '2026-09-15');
        ($this->record)('285.0000000000', '2026-09-30');

        $result = $this->revalue->handle(Carbon::parse('2026-09-30'), $this->actor);

        expect($result['entry'])->not->toBeNull()
            ->and($result['reversal'])->not->toBeNull();

        // The adjustment falls in the period being reported...
        expect($result['entry']?->entry_date->toDateString())->toBe('2026-09-30');
        // ...and unwinds on the first day of the next one.
        expect($result['reversal']?->entry_date->toDateString())->toBe('2026-10-01');

        // A gain debits the asset and credits FX gain/loss.
        $line = $result['entry']?->lines()->where('account_id', $this->usdBank->id)->sole();
        expect((string) $line?->debit_base)->toBeDecimal('6500.0000');

        $fxLine = $result['entry']?->lines()->where('account_id', $this->fx->id)->sole();
        expect((string) $fxLine?->credit_base)->toBeDecimal('6500.0000');
    });

    it('leaves the carrying value untouched once the reversal is counted', function (): void {
        ($this->record)('278.5000000000', '2026-09-01');
        ($this->receive)('1000.00', '278.5000000000', '2026-09-15');
        ($this->record)('285.0000000000', '2026-09-30');

        $this->revalue->handle(Carbon::parse('2026-09-30'), $this->actor);

        // At the period end the balance sheet shows the revalued figure...
        expect(carryingValue($this->usdBank->id, '2026-09-30'))->toBeDecimal('285000.0000');

        /*
         * ...and after the reversal it is back to the rates the transactions
         * actually happened at. Without this, the next revaluation would count
         * the same gain again and the carrying value would drift away from
         * reality.
         */
        expect(carryingValue($this->usdBank->id, '2026-10-31'))->toBeDecimal('278500.0000');
    });

    it('posts a loss the other way', function (): void {
        ($this->record)('285.0000000000', '2026-09-01');
        ($this->receive)('1000.00', '285.0000000000', '2026-09-15');

        // The rupee strengthened: the USD held is worth less.
        ($this->record)('270.0000000000', '2026-09-30');

        $result = $this->revalue->handle(Carbon::parse('2026-09-30'), $this->actor);

        expect($result['adjustments'][0]['difference'])->toBe('-15000.0000');

        $line = $result['entry']?->lines()->where('account_id', $this->usdBank->id)->sole();
        expect((string) $line?->credit_base)->toBeDecimal('15000.0000');

        $fxLine = $result['entry']?->lines()->where('account_id', $this->fx->id)->sole();
        expect((string) $fxLine?->debit_base)->toBeDecimal('15000.0000');
    });

    it('does nothing when the rate has not moved', function (): void {
        ($this->record)('278.5000000000', '2026-09-01');
        ($this->receive)('1000.00', '278.5000000000', '2026-09-15');

        $result = $this->revalue->handle(Carbon::parse('2026-09-30'), $this->actor);

        expect($result['entry'])->toBeNull()
            ->and($result['adjustments'])->toBe([])
            // No entry rather than a zero-value one, which the ledger refuses
            // anyway and which would say nothing.
            ->and(JournalEntry::query()->count())->toBe(1);
    });

    it('ignores accounts held in the base currency', function (): void {
        ($this->record)('278.5000000000', '2026-09-01');
        ($this->record)('285.0000000000', '2026-09-30');

        // Trading only in PKR: a rate move changes nothing on the balance
        // sheet, because nothing is held in another currency.
        $this->post->handle(
            JournalDraft::inBaseCurrency(
                date: Carbon::parse('2026-09-15'),
                currency: 'PKR',
                lines: [
                    JournalLineDraft::debit(ledgerAccount(SystemAccount::AccountsReceivable)->id, '50000.0000'),
                    JournalLineDraft::credit($this->revenue->id, '50000.0000'),
                ],
                source: ['invoice', (string) Str::uuid7(), 'issue'],
            ),
            $this->actor,
        );

        expect($this->revalue->handle(Carbon::parse('2026-09-30'), $this->actor)['adjustments'])
            ->toBe([]);
    });

    it('refuses to revalue the same date twice', function (): void {
        ($this->record)('278.5000000000', '2026-09-01');
        ($this->receive)('1000.00', '278.5000000000', '2026-09-15');
        ($this->record)('285.0000000000', '2026-09-30');

        $this->revalue->handle(Carbon::parse('2026-09-30'), $this->actor);

        // The source id is derived from the date, so the idempotency index
        // refuses the second run rather than doubling the adjustment.
        expect(fn () => $this->revalue->handle(Carbon::parse('2026-09-30'), $this->actor))
            ->toThrow(PostingRefused::class, 'has already posted');

        expect(JournalEntry::query()->where('source_type', 'revaluation')->count())->toBe(2);
    });

    it('refuses to revalue a currency it has no rate for', function (): void {
        ($this->record)('278.5000000000', '2026-09-01');
        ($this->receive)('1000.00', '278.5000000000', '2026-09-15');

        /*
         * A second foreign account, in a currency nobody has recorded a rate
         * for. Revaluation must stop rather than skip it: silently leaving one
         * balance un-revalued produces a balance sheet that is wrong by
         * however far that currency has moved, with nothing to say so.
         */
        $gbpBank = new Account;

        $gbpBank->forceFill([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->organization->getKey(),
            'code' => '1016',
            'name' => 'Bank — GBP',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'currency' => 'GBP',
            'is_active' => true,
            'is_header' => false,
        ])->save();

        $this->post->handle(
            new JournalDraft(
                date: Carbon::parse('2026-09-16'),
                currency: 'GBP',
                baseCurrency: 'PKR',
                exchangeRate: '350.0000000000',
                lines: [
                    JournalLineDraft::debit($gbpBank->id, '500.00'),
                    JournalLineDraft::credit($this->revenue->id, '500.00'),
                ],
                source: ['invoice', (string) Str::uuid7(), 'issue'],
            ),
            $this->actor,
        );

        expect(fn () => $this->revalue->handle(Carbon::parse('2026-09-30'), $this->actor))
            ->toThrow(MissingExchangeRate::class, 'GBP to PKR');

        // And nothing was posted, so a rate can be recorded and the whole
        // revaluation retried.
        expect(JournalEntry::query()->where('source_type', 'revaluation')->count())->toBe(0);
    });

    it('leaves the trial balance balanced', function (): void {
        ($this->record)('278.5000000000', '2026-09-01');
        ($this->receive)('1000.00', '278.5000000000', '2026-09-15');
        ($this->record)('285.0000000000', '2026-09-30');

        $this->revalue->handle(Carbon::parse('2026-09-30'), $this->actor);

        $this->artisan('my-books:verify-ledger')->assertExitCode(0);
    });

    it('audits the revaluation with the figures it used', function (): void {
        ($this->record)('278.5000000000', '2026-09-01');
        ($this->receive)('1000.00', '278.5000000000', '2026-09-15');
        ($this->record)('285.0000000000', '2026-09-30');

        $this->revalue->handle(Carbon::parse('2026-09-30'), $this->actor);

        $audit = AuditLog::query()->where('action', 'accounting.revalued')->sole();

        expect($audit->new_values['as_of'])->toBe('2026-09-30')
            ->and($audit->new_values['adjustments'][0]['rate'])->toBe('285.0000000000')
            ->and($audit->new_values['adjustments'][0]['difference'])->toBe('6500.0000');
    });
});

/**
 * An account's base-currency carrying value up to a date.
 */
function carryingValue(string $accountId, string $upTo): string
{
    /** @var object{net: string|null}|null $row */
    $row = DB::table('journal_lines')
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->where('journal_lines.account_id', $accountId)
        ->whereDate('journal_entries.entry_date', '<=', $upTo)
        ->selectRaw('COALESCE(SUM(journal_lines.debit_base - journal_lines.credit_base), 0) AS net')
        ->first();

    return (string) BigDecimal::of((string) ($row->net ?? '0'))->toScale(4);
}
