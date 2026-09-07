<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\CreateChartOfAccounts;
use App\Domain\Accounting\Actions\CreateFiscalYear;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|---------------------------------------------------------------------------
| What an organisation needs before it can post anything
|---------------------------------------------------------------------------
|
| A chart of accounts and a financial year. Both are created during onboarding
| and both must be idempotent: the wizard can be resumed, and a retried
| request must not produce two accounts each claiming to be "the" AR control
| account.
|
| @see ACCOUNTING_RULES.md §3, §7
*/

beforeEach(function (): void {
    $this->organization = asOrganization(Organization::factory()->create());
    $this->actor = User::factory()->create();
});

describe('the chart of accounts', function (): void {
    it('creates a complete chart with every system role filled exactly once', function (): void {
        $created = app(CreateChartOfAccounts::class)->handle($this->organization, $this->actor);

        expect($created)->toBeGreaterThan(30);

        foreach (SystemAccount::cases() as $role) {
            $matches = Account::query()->where('system_role', $role->value)->count();

            expect($matches)->toBe(1, "System role {$role->value} should be filled exactly once");
        }
    });

    it('gives every account its type normal balance, save for a known list of contras', function (): void {
        app(CreateChartOfAccounts::class)->handle($this->organization);

        $inverted = Account::query()
            ->get()
            ->filter(fn (Account $account): bool => $account->normal_balance !== $account->type->normalBalance())
            ->pluck('code')
            ->sort()
            ->values()
            ->all();

        /*
         * Contra accounts sit inside a type carrying the opposite balance:
         * accumulated depreciation reduces assets, drawings reduce equity,
         * returns and discounts reduce revenue.
         *
         * The list is spelled out rather than derived, so an account that
         * starts inverting by accident fails this test. A silently flipped
         * normal balance changes the sign of a figure on every report that
         * touches it, and nothing else would catch it.
         */
        expect($inverted)->toBe(['1750', '3500', '4800', '4900']);
    });

    it('marks contra accounts with the opposite normal balance within their type', function (): void {
        app(CreateChartOfAccounts::class)->handle($this->organization);

        $returns = ledgerAccount(SystemAccount::SalesReturns);

        expect($returns->type)->toBe(AccountType::Income);
        expect($returns->normal_balance)->toBe(NormalBalance::Debit);
    });

    it('has at least one postable leaf in each of the five root types', function (): void {
        app(CreateChartOfAccounts::class)->handle($this->organization);

        foreach (AccountType::cases() as $type) {
            $postable = Account::query()->postable()->where('type', $type->value)->count();

            expect($postable)->toBeGreaterThan(0, "No postable {$type->value} account exists");
        }
    });

    it('never gives a heading a currency of its own', function (): void {
        app(CreateChartOfAccounts::class)->handle($this->organization);

        expect(Account::query()->where('is_header', true)->whereNotNull('currency')->count())->toBe(0);
    });

    it('is idempotent: a second run adds nothing', function (): void {
        $first = app(CreateChartOfAccounts::class)->handle($this->organization);
        $total = Account::query()->count();

        $second = app(CreateChartOfAccounts::class)->handle($this->organization);

        expect($second)->toBe(0);
        expect(Account::query()->count())->toBe($total);
        expect($first)->toBe($total);
    });

    it('refuses two accounts claiming the same system role, in the database', function (): void {
        app(CreateChartOfAccounts::class)->handle($this->organization);

        expect(fn () => DB::table('accounts')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->organization->getKey(),
            'code' => '9999',
            'name' => 'A second AR control account',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'system_role' => SystemAccount::AccountsReceivable->value,
            'is_active' => true,
            'is_header' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('keeps each organisation chart to itself', function (): void {
        app(CreateChartOfAccounts::class)->handle($this->organization);
        $mine = Account::query()->count();

        $other = Organization::factory()->create();
        asOrganization($other);
        app(CreateChartOfAccounts::class)->handle($other);

        expect(Account::query()->count())->toBe($mine);

        asOrganization($this->organization);
        expect(Account::query()->count())->toBe($mine);
    });

    it('records what it created in the audit trail', function (): void {
        app(CreateChartOfAccounts::class)->handle($this->organization, $this->actor);

        $audit = AuditLog::query()->where('action', 'accounting.chart_created')->sole();

        expect($audit->new_values['jurisdiction'])->toBe('PK');
        expect($audit->new_values['accounts_created'])->toBeGreaterThan(30);
    });
});

describe('the financial year', function (): void {
    it('opens on the organisation fiscal start month, not in January', function (): void {
        // Pakistan's tax year runs July to June.
        $year = app(CreateFiscalYear::class)->handle($this->organization, 2026, $this->actor);

        expect($year->starts_on->toDateString())->toBe('2026-07-01');
        expect($year->ends_on->toDateString())->toBe('2027-06-30');
        expect($year->label)->toBe('2026-27');
    });

    it('labels a calendar-aligned year with a single year', function (): void {
        $january = Organization::factory()->create(['fiscal_year_start_month' => 1]);
        asOrganization($january);

        $year = app(CreateFiscalYear::class)->handle($january, 2026);

        expect($year->label)->toBe('2026');
        expect($year->starts_on->toDateString())->toBe('2026-01-01');
        expect($year->ends_on->toDateString())->toBe('2026-12-31');
    });

    it('creates twelve consecutive periods named by their month', function (): void {
        $year = app(CreateFiscalYear::class)->handle($this->organization, 2026);

        $periods = FiscalPeriod::query()
            ->where('fiscal_year_id', $year->getKey())
            ->orderBy('sequence')
            ->get();

        expect($periods)->toHaveCount(12);
        expect($periods->first()?->label)->toBe('Jul 2026');
        expect($periods->last()?->label)->toBe('Jun 2027');

        // Contiguous: each period starts the day after the previous one ends,
        // so no date falls between two periods.
        $periods->sliding(2)->each(function ($pair): void {
            [$earlier, $later] = [$pair->first(), $pair->last()];

            expect($later->starts_on->toDateString())
                ->toBe($earlier->ends_on->copy()->addDay()->toDateString());
        });

        expect($periods->first()?->starts_on->toDateString())->toBe($year->starts_on->toDateString());
        expect($periods->last()?->ends_on->toDateString())->toBe($year->ends_on->toDateString());
    });

    it('covers every day of the year exactly once', function (): void {
        $year = app(CreateFiscalYear::class)->handle($this->organization, 2026);

        $cursor = Carbon::parse($year->starts_on->toDateString());
        $end = Carbon::parse($year->ends_on->toDateString());

        while ($cursor->lessThanOrEqualTo($end)) {
            $covering = FiscalPeriod::query()
                ->whereDate('starts_on', '<=', $cursor)
                ->whereDate('ends_on', '>=', $cursor)
                ->count();

            expect($covering)->toBe(1, "{$cursor->toDateString()} is covered by {$covering} periods");

            // Sampling weekly keeps this fast while still catching a month
            // boundary that is off by a day.
            $cursor->addWeek();
        }
    });

    it('refuses overlapping periods, in the database', function (): void {
        app(CreateFiscalYear::class)->handle($this->organization, 2026);

        /*
         * Under a second year, so the unique (year, sequence) index is not
         * what refuses the row — the exclusion constraint has to be, since
         * the real hazard is an overlap ACROSS years.
         */
        $secondYearId = (string) Str::uuid7();

        DB::table('fiscal_years')->insert([
            'id' => $secondYearId,
            'organization_id' => $this->organization->getKey(),
            'label' => 'Interfering',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Overlaps July by a single day, which would give a posting date two
        // possible periods and the ledger two answers.
        expect(fn () => DB::table('fiscal_periods')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->organization->getKey(),
            'fiscal_year_id' => $secondYearId,
            'sequence' => 1,
            'label' => 'Overlapping',
            'starts_on' => '2026-07-31',
            'ends_on' => '2026-08-15',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class, 'fiscal_periods_no_overlap');
    });

    it('lets a different organisation hold the same dates', function (): void {
        app(CreateFiscalYear::class)->handle($this->organization, 2026);

        $other = Organization::factory()->create();
        asOrganization($other);

        $theirs = app(CreateFiscalYear::class)->handle($other, 2026);

        expect($theirs->starts_on->toDateString())->toBe('2026-07-01');
        expect(FiscalPeriod::query()->count())->toBe(12);
    });

    it('picks the year covering today when none is given', function (): void {
        $year = app(CreateFiscalYear::class)->handle($this->organization, actor: $this->actor);

        $today = Carbon::now();
        $expectedStart = (int) $today->format('n') >= 7
            ? (int) $today->format('Y')
            : (int) $today->format('Y') - 1;

        expect($year->starts_on->year)->toBe($expectedStart);
        expect($year->starts_on->lessThanOrEqualTo($today))->toBeTrue();
        expect($year->ends_on->greaterThanOrEqualTo($today))->toBeTrue();
    });

    it('records the year it opened in the audit trail', function (): void {
        app(CreateFiscalYear::class)->handle($this->organization, 2026, $this->actor);

        $audit = AuditLog::query()->where('action', 'accounting.fiscal_year_created')->sole();

        expect($audit->new_values['label'])->toBe('2026-27');
        expect($audit->user_id)->toBe($this->actor->id);
    });

    it('refuses a second year with the same label', function (): void {
        app(CreateFiscalYear::class)->handle($this->organization, 2026);

        expect(fn () => app(CreateFiscalYear::class)->handle($this->organization, 2026))
            ->toThrow(QueryException::class);
    });
});
