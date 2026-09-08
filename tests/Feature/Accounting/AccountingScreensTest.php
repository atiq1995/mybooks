<?php

declare(strict_types=1);

use App\Domain\Access\Enums\Role;
use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
|---------------------------------------------------------------------------
| The accounting screens, over HTTP
|---------------------------------------------------------------------------
|
| Two things are tested here that the domain tests cannot reach: that every
| write is refused without its own permission, and that a URL naming another
| organisation's record produces a 404 rather than that record.
|
| Authorisation is asserted per ROLE rather than per permission, because the
| roles are what people are actually given — a viewer who can post is a real
| failure, and a permission list that looks right while a role composes it
| wrongly would pass a permission-level test.
*/

beforeEach(function (): void {
    $this->organization = Organization::factory()->create(['name' => 'Alpha Traders']);
    $this->year = withLedger($this->organization);

    $this->owner = actingAsMember($this->organization, Role::Owner->value);

    $this->ar = ledgerAccount(SystemAccount::AccountsReceivable);
    $this->revenue = Account::query()->where('type', 'income')->where('is_header', false)
        ->whereNull('system_role')->orderBy('code')->firstOrFail();

    $this->date = Carbon::parse($this->year->starts_on->toDateString())->addMonth();

    $this->postEntry = fn (): JournalEntry => app(PostJournalEntry::class)->handle(
        JournalDraft::inBaseCurrency(
            date: $this->date,
            currency: 'PKR',
            lines: [
                JournalLineDraft::debit($this->ar->id, '25000.0000'),
                JournalLineDraft::credit($this->revenue->id, '25000.0000'),
            ],
            source: ['invoice', null, 'issue'],
        ),
        $this->owner,
    );
});

describe('the chart of accounts', function (): void {
    it('lists every account with a balance summed from the ledger', function (): void {
        ($this->postEntry)();

        $this->get('/accounting/accounts')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->component('Accounting/Accounts/Index')
                    ->where('baseCurrency', 'PKR')
                    ->where('can.manage', true);

                $accounts = collect($page->toArray()['props']['accounts']);

                $ar = $accounts->firstWhere('code', $this->ar->code);

                // Debit-normal, debited 25,000: a positive balance means "as
                // this kind of account expects".
                expect($ar['balance'])->toBe('25000.0000');

                // A heading holds no postings, so it reports no balance rather
                // than a misleading zero.
                expect($accounts->firstWhere('is_header', true)['balance'])->toBeNull();
            });
    });

    it('hides archived accounts unless asked', function (): void {
        $this->revenue->forceFill(['archived_at' => now(), 'is_active' => false])->save();

        $this->get('/accounting/accounts')
            ->assertInertia(fn (Assert $page) => $page->where(
                'accounts',
                fn (Collection $accounts): bool => $accounts
                    ->doesntContain('code', $this->revenue->code),
            ));

        $this->get('/accounting/accounts?archived=1')
            ->assertInertia(fn (Assert $page) => $page->where(
                'accounts',
                fn (Collection $accounts): bool => $accounts
                    ->contains('code', $this->revenue->code),
            ));
    });

    it('creates an account and audits it', function (): void {
        $this->post('/accounting/accounts', [
            'code' => '1215',
            'name' => 'Trade Receivables — Retail',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ])->assertSessionHas('success');

        $account = Account::query()->where('code', '1215')->sole();

        expect($account->name)->toBe('Trade Receivables — Retail')
            // A user-created account never claims a system role: two accounts
            // both claiming to be "the" AR control account is unresolvable.
            ->and($account->system_role)->toBeNull()
            ->and(AuditLog::query()->where('action', 'accounting.account_created')->count())->toBe(1);
    });

    it('refuses a duplicate code within the organisation', function (): void {
        $this->post('/accounting/accounts', [
            'code' => $this->ar->code,
            'name' => 'A second receivables account',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ])->assertSessionHasErrors('code');
    });

    it('lets another organisation use a code this one has taken', function (): void {
        $other = Organization::factory()->create();
        actingAsMember($other, Role::Owner->value);

        $this->post('/accounting/accounts', [
            'code' => $this->ar->code,
            'name' => 'Their receivables',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ])->assertSessionHasNoErrors();
    });

    it('refuses to file an account under something that is not a heading', function (): void {
        $this->post('/accounting/accounts', [
            'code' => '1216',
            'name' => 'Filed under a leaf',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'parent_id' => $this->ar->id,
        ])->assertSessionHasErrors('parent_id');
    });

    it('archives rather than deletes, keeping the ledger readable', function (): void {
        ($this->postEntry)();

        $this->delete("/accounting/accounts/{$this->revenue->code}")
            ->assertSessionHas('success');

        $archived = $this->revenue->fresh();

        expect($archived?->archived_at)->not->toBeNull()
            ->and($archived?->is_active)->toBeFalse()
            // The entry that used it is untouched.
            ->and($archived?->journalLines()->count())->toBe(1);
    });

    it('refuses to archive a system account', function (): void {
        $this->delete("/accounting/accounts/{$this->ar->code}")
            ->assertSessionHas('error');

        expect($this->ar->fresh()?->archived_at)->toBeNull();
    });

    it('refuses to archive a heading that still groups active accounts', function (): void {
        $heading = Account::query()->where('is_header', true)
            ->whereHas('children', fn ($q) => $q->whereNull('archived_at'))
            ->firstOrFail();

        $this->delete("/accounting/accounts/{$heading->code}")->assertSessionHas('error');

        expect($heading->fresh()?->archived_at)->toBeNull();
    });

    it('keeps the type fixed once the account has posted', function (): void {
        ($this->postEntry)();

        $this->patch("/accounting/accounts/{$this->revenue->code}", [
            'name' => 'Renamed but not retyped',
            'type' => 'expense',
            'normal_balance' => 'debit',
        ])->assertSessionHas('success');

        $updated = $this->revenue->fresh();

        // The rename lands; the retype does not. Changing it retroactively
        // would flip the sign of every figure this account has contributed.
        expect($updated?->name)->toBe('Renamed but not retyped')
            ->and($updated?->type->value)->toBe('income');
    });

    it('404s on an account code belonging to another organisation', function (): void {
        $other = Organization::factory()->create();

        // A code only THAT organisation has. Using one from the standard
        // template would prove nothing: both charts contain it, so the binding
        // would legitimately resolve to our own account.
        app(TenantContext::class)->runAs($other, function () use ($other): void {
            withLedger($other);

            $account = new Account;

            $account->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $other->getKey(),
                'code' => 'THEIRS-1',
                'name' => 'Only in the other organisation',
                'type' => 'asset',
                'normal_balance' => 'debit',
                'is_active' => true,
                'is_header' => false,
            ])->save();
        });

        actingAsMember($this->organization, Role::Owner->value);

        $this->patch('/accounting/accounts/THEIRS-1', [
            'name' => 'Reaching across',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ])->assertNotFound();

        $this->delete('/accounting/accounts/THEIRS-1')->assertNotFound();
    });
});

describe('manual journals', function (): void {
    it('lists posted entries newest first', function (): void {
        ($this->postEntry)();

        $this->get('/accounting/journals')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Journals/Index')
                ->where('entries.total', 1)
                ->where('can.post', true)
                ->where('can.reverse', true),
            );
    });

    it('posts a balanced entry written by hand', function (): void {
        $this->post('/accounting/journals', [
            'date' => $this->date->toDateString(),
            'memo' => 'Depreciation for the month',
            'lines' => [
                ['account_id' => $this->ar->id, 'side' => 'debit', 'amount' => '1500.00'],
                ['account_id' => $this->revenue->id, 'side' => 'credit', 'amount' => '1500.00'],
            ],
        ])->assertRedirect('/accounting/journals/JE-000001');

        $entry = JournalEntry::query()->sole();

        expect($entry->source_type)->toBe('manual')
            ->and($entry->memo)->toBe('Depreciation for the month')
            ->and((string) $entry->total_debit)->toBe('1500.0000');
    });

    it('posts two manual journals on the same day', function (): void {
        // A manual journal carries no source id, so the idempotency index must
        // not treat the second one as a duplicate of the first.
        foreach (range(1, 2) as $ignored) {
            $this->post('/accounting/journals', [
                'date' => $this->date->toDateString(),
                'lines' => [
                    ['account_id' => $this->ar->id, 'side' => 'debit', 'amount' => '10.00'],
                    ['account_id' => $this->revenue->id, 'side' => 'credit', 'amount' => '10.00'],
                ],
            ])->assertSessionHasNoErrors();
        }

        expect(JournalEntry::query()->count())->toBe(2);
    });

    it('refuses an entry that does not balance, naming the difference', function (): void {
        $this->post('/accounting/journals', [
            'date' => $this->date->toDateString(),
            'lines' => [
                ['account_id' => $this->ar->id, 'side' => 'debit', 'amount' => '100.00'],
                ['account_id' => $this->revenue->id, 'side' => 'credit', 'amount' => '99.99'],
            ],
        ])->assertSessionHasErrors('lines');

        expect(JournalEntry::query()->count())->toBe(0);

        expect(session('errors')?->first('lines'))->toContain('0.0100');
    });

    it('refuses a single-line entry, which cannot balance', function (): void {
        $this->post('/accounting/journals', [
            'date' => $this->date->toDateString(),
            'lines' => [
                ['account_id' => $this->ar->id, 'side' => 'debit', 'amount' => '100.00'],
            ],
        ])->assertSessionHasErrors('lines');
    });

    it('refuses a zero or negative line, and says which side to use instead', function (): void {
        $this->post('/accounting/journals', [
            'date' => $this->date->toDateString(),
            'lines' => [
                ['account_id' => $this->ar->id, 'side' => 'debit', 'amount' => '-100.00'],
                ['account_id' => $this->revenue->id, 'side' => 'credit', 'amount' => '-100.00'],
            ],
        ])->assertSessionHasErrors(['lines.0.amount', 'lines.1.amount']);

        expect(session('errors')?->first('lines.0.amount'))->toContain('a credit');
    });

    it('refuses a heading account', function (): void {
        $heading = Account::query()->where('is_header', true)->firstOrFail();

        $this->post('/accounting/journals', [
            'date' => $this->date->toDateString(),
            'lines' => [
                ['account_id' => $heading->id, 'side' => 'debit', 'amount' => '10.00'],
                ['account_id' => $this->revenue->id, 'side' => 'credit', 'amount' => '10.00'],
            ],
        ])->assertSessionHasErrors('lines.0.account_id');
    });

    it('refuses an account from another organisation as simply invalid', function (): void {
        $other = Organization::factory()->create();
        $foreignId = app(TenantContext::class)->runAs(
            $other,
            function () use ($other): string {
                withLedger($other);

                return Account::query()->postable()->orderBy('code')->firstOrFail()->id;
            },
        );

        actingAsMember($this->organization, Role::Owner->value);

        $this->post('/accounting/journals', [
            'date' => $this->date->toDateString(),
            'lines' => [
                ['account_id' => $foreignId, 'side' => 'debit', 'amount' => '10.00'],
                ['account_id' => $this->revenue->id, 'side' => 'credit', 'amount' => '10.00'],
            ],
        ])->assertSessionHasErrors('lines.0.account_id');

        expect(JournalEntry::query()->count())->toBe(0);
    });

    it('refuses a closed period, and accepts one with the override', function (): void {
        FiscalPeriod::query()
            ->whereDate('starts_on', '<=', $this->date)
            ->whereDate('ends_on', '>=', $this->date)
            ->sole()
            ->forceFill(['status' => PeriodStatus::Closed, 'closed_at' => now()])
            ->save();

        $payload = [
            'date' => $this->date->toDateString(),
            'lines' => [
                ['account_id' => $this->ar->id, 'side' => 'debit', 'amount' => '10.00'],
                ['account_id' => $this->revenue->id, 'side' => 'credit', 'amount' => '10.00'],
            ],
        ];

        $this->post('/accounting/journals', $payload)->assertSessionHasErrors('date');

        // The owner holds the override, so asking for it works — and the audit
        // trail says it was used.
        $this->post('/accounting/journals', [...$payload, 'post_to_closed_period' => true])
            ->assertSessionHasNoErrors();

        expect(AuditLog::query()->where('action', 'journal.posted')->sole()->description)
            ->toContain('CLOSED period');
    });

    it('shows an entry with both directions of its correction chain', function (): void {
        $entry = ($this->postEntry)();

        $this->post("/accounting/journals/{$entry->entry_no}/reverse", [
            'reason' => 'Invoice cancelled',
        ])->assertSessionHas('success');

        $reversal = JournalEntry::query()->where('reverses_entry_id', $entry->id)->sole();

        $this->get("/accounting/journals/{$entry->entry_no}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Journals/Show')
                ->where('entry.status', 'reversed')
                ->where('entry.reversed_by.entry_no', $reversal->entry_no)
                // An entry that has been reversed cannot be reversed again.
                ->where('can.reverse', false),
            );

        $this->get("/accounting/journals/{$reversal->entry_no}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('entry.reverses.entry_no', $entry->entry_no)
                // Nor can a reversal itself be reversed: that would restore
                // the original error.
                ->where('can.reverse', false),
            );
    });

    it('404s on an entry number from another organisation', function (): void {
        $other = Organization::factory()->create();

        $theirEntryNo = app(TenantContext::class)->runAs(
            $other,
            function () use ($other): string {
                withLedger($other);

                return app(PostJournalEntry::class)->handle(
                    JournalDraft::inBaseCurrency(
                        date: Carbon::parse($this->year->starts_on->toDateString())->addMonth(),
                        currency: 'PKR',
                        lines: [
                            JournalLineDraft::debit(
                                ledgerAccount(SystemAccount::AccountsReceivable)->id,
                                '5.00',
                            ),
                            JournalLineDraft::credit(
                                Account::query()->where('type', 'income')
                                    ->where('is_header', false)->orderBy('code')->firstOrFail()->id,
                                '5.00',
                            ),
                        ],
                        source: ['manual', null, 'issue'],
                    ),
                )->entry_no;
            },
        );

        actingAsMember($this->organization, Role::Owner->value);

        $this->get("/accounting/journals/{$theirEntryNo}")->assertNotFound();
        $this->post("/accounting/journals/{$theirEntryNo}/reverse")->assertNotFound();
    });
});

describe('the general ledger and trial balance', function (): void {
    it('opens with no account chosen rather than guessing one', function (): void {
        $this->get('/accounting/general-ledger')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/GeneralLedger')
                ->where('selected', null)
                ->where('ledger', null),
            );
    });

    it('reports an opening balance, the movements and a closing balance', function (): void {
        ($this->postEntry)();

        $from = $this->date->copy()->startOfMonth();

        $this->get("/accounting/general-ledger?account={$this->ar->id}&from={$from->toDateString()}&to={$this->date->copy()->endOfMonth()->toDateString()}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.code', $this->ar->code)
                // Nothing before this month, so it opens at zero and closes at
                // what moved.
                ->where('ledger.opening', '0')
                ->where('ledger.movement_debit', '25000.0000')
                ->where('ledger.closing', '25000.0000'),
            );
    });

    it('carries the earlier history into the opening balance', function (): void {
        ($this->postEntry)();

        // A window that starts AFTER the entry: the movement is now history,
        // so it must appear as the opening figure rather than vanish.
        $from = $this->date->copy()->addMonth()->startOfMonth();

        $this->get("/accounting/general-ledger?account={$this->ar->id}&from={$from->toDateString()}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('ledger.opening', '25000.0000')
                ->where('ledger.movement_debit', '0')
                ->where('ledger.closing', '25000.0000'),
            );
    });

    it('ignores an account id from another organisation', function (): void {
        $other = Organization::factory()->create();
        $foreignId = app(TenantContext::class)->runAs(
            $other,
            function () use ($other): string {
                withLedger($other);

                return Account::query()->postable()->orderBy('code')->firstOrFail()->id;
            },
        );

        actingAsMember($this->organization, Role::Owner->value);

        // Silently unselected rather than an error: the id is not a thing that
        // exists as far as this organisation is concerned.
        $this->get("/accounting/general-ledger?account={$foreignId}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selected', null));
    });

    it('reports a trial balance that balances', function (): void {
        ($this->postEntry)();

        $this->get('/accounting/trial-balance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/TrialBalance')
                ->where('totals.balances', true)
                ->where('totals.debit', '25000.0000')
                ->where('totals.credit', '25000.0000')
                ->where('totals.difference', '0.0000'),
            );
    });

    it('shows each account on one side only, as its net position', function (): void {
        ($this->postEntry)();

        $this->get('/accounting/trial-balance')
            ->assertInertia(function (Assert $page): void {
                $lines = collect($page->toArray()['props']['lines']);

                // Two accounts moved, so two lines — not four, which is what
                // showing both columns per account would produce.
                expect($lines)->toHaveCount(2);

                $ar = $lines->firstWhere('code', $this->ar->code);

                expect($ar['debit'])->toBe('25000.0000')
                    ->and($ar['credit'])->toBe('0');
            });
    });

    it('excludes accounts with nothing in them unless asked', function (): void {
        ($this->postEntry)();

        $this->get('/accounting/trial-balance?zero=1')
            ->assertInertia(fn (Assert $page) => $page->where(
                'lines',
                // Every postable account appears, not only the two that moved.
                fn (Collection $lines): bool => $lines->count() > 2,
            ));
    });
});

describe('fiscal periods', function (): void {
    it('lists years with their periods and entry counts', function (): void {
        ($this->postEntry)();

        $this->get('/accounting/periods')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->component('Accounting/Periods')->where('can.manage', true);

                $periods = collect($page->toArray()['props']['years'][0]['periods']);

                expect($periods)->toHaveCount(12);

                // Exactly one period holds the entry we posted.
                expect($periods->where('entries', 1))->toHaveCount(1);
            });
    });

    it('closes, reopens, then locks a period', function (): void {
        $period = FiscalPeriod::query()->where('sequence', 1)->sole();

        $this->patch("/accounting/periods/{$period->id}", ['status' => 'closed'])
            ->assertSessionHas('success');

        expect($period->fresh()?->status)->toBe(PeriodStatus::Closed);

        $this->patch("/accounting/periods/{$period->id}", ['status' => 'open'])
            ->assertSessionHas('success');

        expect($period->fresh()?->status)->toBe(PeriodStatus::Open)
            // Reopening clears the closure, so the record does not claim it is
            // both open and closed at once.
            ->and($period->fresh()?->closed_at)->toBeNull();

        $this->patch("/accounting/periods/{$period->id}", ['status' => 'locked'])
            ->assertSessionHas('success');

        expect($period->fresh()?->status)->toBe(PeriodStatus::Locked);
    });

    it('never reopens a locked period', function (): void {
        $period = FiscalPeriod::query()->where('sequence', 1)->sole();
        $period->forceFill(['status' => PeriodStatus::Locked, 'closed_at' => now()])->save();

        $this->patch("/accounting/periods/{$period->id}", ['status' => 'open'])
            ->assertSessionHas('error');

        expect($period->fresh()?->status)->toBe(PeriodStatus::Locked);
    });

    it('audits every state change with what it was before', function (): void {
        $period = FiscalPeriod::query()->where('sequence', 1)->sole();

        $this->patch("/accounting/periods/{$period->id}", ['status' => 'closed']);

        $audit = AuditLog::query()->where('action', 'accounting.period_closed')->sole();

        expect($audit->old_values['status'])->toBe('open')
            ->and($audit->new_values['status'])->toBe('closed')
            ->and($audit->user_id)->toBe($this->owner->id);
    });

    it('opens another financial year', function (): void {
        $next = $this->year->starts_on->year + 1;

        $this->post('/accounting/fiscal-years', ['starting_year' => $next])
            ->assertSessionHas('success');

        expect(FiscalPeriod::query()->count())->toBe(24);
    });

    it('refuses a year that would overlap an existing one', function (): void {
        $this->post('/accounting/fiscal-years', ['starting_year' => $this->year->starts_on->year])
            ->assertSessionHasErrors('starting_year');

        expect(FiscalPeriod::query()->count())->toBe(12);
    });

    it('404s on a period belonging to another organisation', function (): void {
        $other = Organization::factory()->create();
        $theirPeriodId = app(TenantContext::class)->runAs(
            $other,
            function () use ($other): string {
                withLedger($other);

                return FiscalPeriod::query()->where('sequence', 1)->sole()->id;
            },
        );

        actingAsMember($this->organization, Role::Owner->value);

        $this->patch("/accounting/periods/{$theirPeriodId}", ['status' => 'closed'])
            ->assertNotFound();
    });
});

describe('authorisation, by role', function (): void {
    it('lets a viewer read the ledger and change nothing', function (): void {
        $entry = ($this->postEntry)();

        actingAsMember($this->organization, Role::Viewer->value);

        // Reads.
        $this->get('/accounting/accounts')->assertOk();
        $this->get('/accounting/journals')->assertOk();
        $this->get('/accounting/general-ledger')->assertOk();
        $this->get('/accounting/trial-balance')->assertOk();
        $this->get('/accounting/periods')->assertOk();

        // Writes, every one refused.
        $this->get('/accounting/journals/new')->assertForbidden();

        $this->post('/accounting/journals', [
            'date' => $this->date->toDateString(),
            'lines' => [
                ['account_id' => $this->ar->id, 'side' => 'debit', 'amount' => '10.00'],
                ['account_id' => $this->revenue->id, 'side' => 'credit', 'amount' => '10.00'],
            ],
        ])->assertForbidden();

        $this->post('/accounting/accounts', [
            'code' => '9998',
            'name' => 'Should not exist',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ])->assertForbidden();

        $this->delete("/accounting/accounts/{$this->revenue->code}")->assertForbidden();
        $this->post("/accounting/journals/{$entry->entry_no}/reverse")->assertForbidden();

        $this->patch(
            '/accounting/periods/'.FiscalPeriod::query()->where('sequence', 1)->sole()->id,
            ['status' => 'closed'],
        )->assertForbidden();

        expect(JournalEntry::query()->count())->toBe(1)
            ->and(Account::query()->where('code', '9998')->exists())->toBeFalse();
    });

    it('lets a bookkeeper prepare but never post', function (): void {
        actingAsMember($this->organization, Role::Bookkeeper->value);

        $this->get('/accounting/journals')->assertOk();

        // The separation of duties that matters: preparing a document is not
        // posting to the ledger.
        $this->post('/accounting/journals', [
            'date' => $this->date->toDateString(),
            'lines' => [
                ['account_id' => $this->ar->id, 'side' => 'debit', 'amount' => '10.00'],
                ['account_id' => $this->revenue->id, 'side' => 'credit', 'amount' => '10.00'],
            ],
        ])->assertForbidden();

        expect(JournalEntry::query()->count())->toBe(0);
    });

    it('lets an accountant post, reverse and manage periods', function (): void {
        actingAsMember($this->organization, Role::Accountant->value);

        $this->post('/accounting/journals', [
            'date' => $this->date->toDateString(),
            'lines' => [
                ['account_id' => $this->ar->id, 'side' => 'debit', 'amount' => '10.00'],
                ['account_id' => $this->revenue->id, 'side' => 'credit', 'amount' => '10.00'],
            ],
        ])->assertSessionHasNoErrors();

        $entry = JournalEntry::query()->sole();

        $this->post("/accounting/journals/{$entry->entry_no}/reverse")
            ->assertSessionHas('success');

        $this->patch(
            '/accounting/periods/'.FiscalPeriod::query()->where('sequence', 1)->sole()->id,
            ['status' => 'closed'],
        )->assertSessionHas('success');
    });

    it('does not offer an accountant the closed-period override', function (): void {
        // Posting into a closed period is an owner/admin power, deliberately
        // separate from posting.
        actingAsMember($this->organization, Role::Accountant->value);

        $this->get('/accounting/journals/new')
            ->assertInertia(fn (Assert $page) => $page->where('canPostToClosedPeriod', false));

        FiscalPeriod::query()
            ->whereDate('starts_on', '<=', $this->date)
            ->whereDate('ends_on', '>=', $this->date)
            ->sole()
            ->forceFill(['status' => PeriodStatus::Closed, 'closed_at' => now()])
            ->save();

        // Asking for the override anyway changes nothing: the controller
        // re-checks the permission rather than trusting the flag.
        $this->post('/accounting/journals', [
            'date' => $this->date->toDateString(),
            'post_to_closed_period' => true,
            'lines' => [
                ['account_id' => $this->ar->id, 'side' => 'debit', 'amount' => '10.00'],
                ['account_id' => $this->revenue->id, 'side' => 'credit', 'amount' => '10.00'],
            ],
        ])->assertSessionHasErrors('date');

        expect(JournalEntry::query()->count())->toBe(0);
    });

    it('refuses everything to somebody with no membership', function (): void {
        $stranger = User::factory()->create();

        /*
         * No membership means no tenant context, and every accounting Gate
         * resolves against the active organisation — so it fails closed
         * rather than falling back to "any organisation this user can see".
         */
        $this->actingAs($stranger)->get('/accounting/accounts')->assertForbidden();
        $this->actingAs($stranger)->get('/accounting/trial-balance')->assertForbidden();
    });
});

describe('the year-end close', function (): void {
    it('closes a year and reports what it moved', function (): void {
        ($this->postEntry)();

        $this->post("/accounting/fiscal-years/{$this->year->id}/close")
            ->assertSessionHas('success');

        $closing = JournalEntry::query()->where('source_type', 'closing')->sole();

        expect($closing->entry_date->toDateString())->toBe($this->year->ends_on->toDateString())
            ->and($this->year->fresh()?->closing_entry_id)->toBe($closing->id);

        $this->get('/accounting/periods')
            ->assertInertia(fn (Assert $page) => $page
                ->where('years.0.status', 'closed')
                ->where('years.0.closing_entry_no', $closing->entry_no)
                // A closed year cannot be closed again.
                ->where('years.0.can_close', false),
            );
    });

    it('refuses to close a year twice', function (): void {
        ($this->postEntry)();

        $this->post("/accounting/fiscal-years/{$this->year->id}/close");

        $this->post("/accounting/fiscal-years/{$this->year->id}/close")
            ->assertSessionHas('error');

        expect(JournalEntry::query()->where('source_type', 'closing')->count())->toBe(1);
    });

    it('needs its own permission, which an accountant has and a viewer does not', function (): void {
        ($this->postEntry)();

        actingAsMember($this->organization, Role::Viewer->value);
        $this->post("/accounting/fiscal-years/{$this->year->id}/close")->assertForbidden();

        actingAsMember($this->organization, Role::Bookkeeper->value);
        $this->post("/accounting/fiscal-years/{$this->year->id}/close")->assertForbidden();

        expect(JournalEntry::query()->where('source_type', 'closing')->count())->toBe(0);

        actingAsMember($this->organization, Role::Accountant->value);
        $this->post("/accounting/fiscal-years/{$this->year->id}/close")
            ->assertSessionHas('success');
    });

    it('404s on a year belonging to another organisation', function (): void {
        $other = Organization::factory()->create();

        $theirYearId = app(TenantContext::class)->runAs(
            $other,
            fn (): string => withLedger($other)->id,
        );

        actingAsMember($this->organization, Role::Owner->value);

        $this->post("/accounting/fiscal-years/{$theirYearId}/close")->assertNotFound();
    });
});

describe('currencies', function (): void {
    it('shows recorded rates and offers only the currencies actually held', function (): void {
        $usdBank = new Account;

        $usdBank->forceFill([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->organization->getKey(),
            'code' => '1015',
            'name' => 'Bank — USD',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'currency' => 'USD',
            'is_active' => true,
            'is_header' => false,
        ])->save();

        $this->post('/accounting/currencies/rates', [
            'from_currency' => 'usd',
            'to_currency' => 'pkr',
            'rate' => '278.50',
            'effective_on' => $this->date->toDateString(),
        ])->assertSessionHas('success');

        $this->get('/accounting/currencies')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Currencies')
                ->where('baseCurrency', 'PKR')
                // Lower case in, canonical out.
                ->where('rates.0.from_currency', 'USD')
                ->where('rates.0.rate', '278.5000000000')
                ->where('foreignCurrencies', ['USD'])
                ->where('can.manage_rates', true),
            );
    });

    it('refuses a rate from a currency to itself', function (): void {
        $this->post('/accounting/currencies/rates', [
            'from_currency' => 'PKR',
            'to_currency' => 'PKR',
            'rate' => '1',
            'effective_on' => $this->date->toDateString(),
        ])->assertSessionHasErrors('to_currency');
    });

    it('reports nothing to revalue when everything is held in the base currency', function (): void {
        ($this->postEntry)();

        $this->get('/accounting/currencies')
            ->assertInertia(fn (Assert $page) => $page
                ->where('revaluation.adjustments', [])
                ->where('revaluation.error', null),
            );

        $this->post('/accounting/currencies/revalue', ['as_of' => $this->date->toDateString()])
            ->assertSessionHas('success');

        expect(JournalEntry::query()->where('source_type', 'revaluation')->count())->toBe(0);
    });

    it('needs the accounting settings permission to record a rate', function (): void {
        actingAsMember($this->organization, Role::Viewer->value);

        $this->post('/accounting/currencies/rates', [
            'from_currency' => 'USD',
            'to_currency' => 'PKR',
            'rate' => '278.50',
            'effective_on' => $this->date->toDateString(),
        ])->assertForbidden();

        actingAsMember($this->organization, Role::Bookkeeper->value);

        // A bookkeeper cannot post, and must not be able to move the balance
        // sheet by editing a rate either.
        $this->post('/accounting/currencies/revalue', ['as_of' => $this->date->toDateString()])
            ->assertForbidden();
    });
});
