<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\CreateChartOfAccounts;
use App\Domain\Accounting\Actions\CreateFiscalYear;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Organizations\Enums\MembershipStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Http\Middleware\EstablishTenantContext;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|---------------------------------------------------------------------------
| Suites
|---------------------------------------------------------------------------
|
| Unit tests are pure and touch no database. Everything else runs against
| real PostgreSQL inside a transaction that is rolled back after each test.
| See phpunit.xml for why the tests connect as the schema owner and how RLS
| is exercised regardless.
*/

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Accounting', 'Browser');

/*
|---------------------------------------------------------------------------
| Expectations
|---------------------------------------------------------------------------
*/

/**
 * Assert a decimal string is exactly equal — as a string, never via float.
 *
 *     expect($invoice->total->getAmount()->__toString())->toBeDecimal('117100.0000');
 */
expect()->extend('toBeDecimal', function (string $expected) {
    $actual = (string) $this->value;

    expect($actual)->toBe(
        $expected,
        "Expected decimal {$expected}, got {$actual}. Compared as strings: floats do not exist here.",
    );

    return $this;
});

/*
|---------------------------------------------------------------------------
| Helpers
|---------------------------------------------------------------------------
*/

/**
 * Make an organisation the active tenant for the remainder of the test.
 *
 * Sets the application-layer context. The database-layer setting is applied
 * separately by tests that exercise RLS, because in the default test
 * connection (schema owner) it has no effect.
 */
function asOrganization(Organization $organization): Organization
{
    app(TenantContext::class)->set($organization);

    return $organization;
}

/**
 * A user who is an active member of the given organisation with the given
 * role, signed in with that organisation active in the session.
 */
function actingAsMember(Organization $organization, string $role = 'owner'): User
{
    $user = User::factory()->create();

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $user->getKey(),
        'role' => $role,
        'status' => MembershipStatus::Active,
        'joined_at' => now(),
    ]);

    $user->forceFill(['last_organization_id' => $organization->getKey()])->save();

    test()->actingAs($user)
        ->withSession([EstablishTenantContext::SESSION_KEY => $organization->getKey()]);

    asOrganization($organization);

    return $user;
}

/**
 * Give an organisation the two things a posting needs to exist: a chart of
 * accounts and an open financial year.
 *
 * Makes the organisation the active tenant as a side effect, because both
 * Actions read the tenant scope and there is no sensible way to call them
 * without one.
 */
function withLedger(Organization $organization, ?int $startingYear = null): FiscalYear
{
    asOrganization($organization);

    app(CreateChartOfAccounts::class)->handle($organization);

    return app(CreateFiscalYear::class)->handle($organization, $startingYear);
}

/**
 * An account of the active organisation, by system role or by code.
 *
 * Tests name accounts by the role they play — "the AR control account" — not
 * by a number that a template revision could renumber.
 */
function ledgerAccount(SystemAccount|string $account): Account
{
    $query = Account::query();

    $account instanceof SystemAccount
        ? $query->where('system_role', $account->value)
        : $query->where('code', $account);

    return $query->sole();
}

/**
 * Wrap a write that the database is expected to refuse in its own savepoint.
 *
 * PostgreSQL aborts the whole transaction on error, so a test that asserts two
 * refusals in a row would see "current transaction is aborted" for the second
 * one instead of the constraint it is actually testing. A nested transaction
 * is a SAVEPOINT, which rolls back to a usable state and rethrows.
 */
function refused(Closure $write): Closure
{
    return static fn () => DB::transaction($write);
}
