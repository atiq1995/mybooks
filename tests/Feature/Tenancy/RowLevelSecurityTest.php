<?php

declare(strict_types=1);

use App\Domain\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The database-layer half of tenant isolation.
 *
 * The suite connects as the schema owner, which bypasses RLS — so these tests
 * switch to the application role for the duration of each assertion and
 * observe exactly what production traffic would see. Raw SQL on purpose: the
 * point is to prove the database enforces this with no help from Eloquent.
 */
beforeEach(function (): void {
    $this->alpha = Organization::factory()->create();
    $this->beta = Organization::factory()->create();

    app(TenantContext::class)->runUnscoped(function (): void {
        DB::table('audit_logs')->insert([
            [
                'id' => (string) Str::uuid7(),
                'organization_id' => $this->alpha->getKey(),
                'action' => 'alpha.event',
                'actor_type' => 'system',
                'channel' => 'console',
                'created_at' => now(),
            ],
            [
                'id' => (string) Str::uuid7(),
                'organization_id' => $this->beta->getKey(),
                'action' => 'beta.event',
                'actor_type' => 'system',
                'channel' => 'console',
                'created_at' => now(),
            ],
        ]);
    });

    // Everything below runs as the application role. RESET ROLE in afterEach
    // returns the connection to the owner so RefreshDatabase can roll back.
    DB::statement('SET ROLE my_books_app');
});

afterEach(function (): void {
    DB::statement("SELECT set_config('app.organization_id', '', false), set_config('app.user_id', '', false)");
    DB::statement('RESET ROLE');
});

it('confirms the application role cannot bypass row-level security', function (): void {
    $role = DB::selectOne('SELECT rolbypassrls FROM pg_roles WHERE rolname = current_user');

    expect($role->rolbypassrls)->toBeFalse();
});

it('returns no rows at all when no tenant context is set', function (): void {
    expect(DB::table('audit_logs')->count())->toBe(0)
        ->and(DB::table('organizations')->count())->toBe(0);
});

it('returns only the active organisation\'s rows once context is set', function (): void {
    publishTenant($this->alpha);

    $rows = DB::table('audit_logs')->pluck('action');

    expect($rows->all())->toBe(['alpha.event'])
        ->and(DB::table('organizations')->pluck('id')->all())->toBe([$this->alpha->getKey()]);
});

it('refuses a write that names another organisation', function (): void {
    publishTenant($this->alpha);

    // Inside a nested transaction (a savepoint), so the refused statement
    // does not abort the suite's outer test transaction and leave afterEach
    // unable to RESET ROLE.
    DB::transaction(fn () => DB::table('audit_logs')->insert([
        'id' => (string) Str::uuid7(),
        'organization_id' => $this->beta->getKey(),
        'action' => 'sneaky',
        'actor_type' => 'system',
        'channel' => 'console',
        'created_at' => now(),
    ]));
})->throws(QueryException::class, 'row-level security policy');

it('lets a user list only the organisations they belong to, before any is active', function (): void {
    // Fixtures are created as the owner — the application role cannot insert
    // a membership without an organisation context, which is the point.
    DB::statement('RESET ROLE');
    $user = actingAsMember($this->alpha);
    DB::statement('SET ROLE my_books_app');
    app(TenantContext::class)->clear();

    // Only the user is published — this is the organisation switcher's
    // situation, where no organisation is active yet.
    DB::statement("SELECT set_config('app.user_id', ?, false)", [$user->getKey()]);

    expect(DB::table('organizations')->pluck('id')->all())->toBe([$this->alpha->getKey()]);
});

it('does not let the audit trail be altered even by the owner', function (): void {
    DB::statement('RESET ROLE');

    DB::transaction(fn () => DB::table('audit_logs')
        ->where('action', 'alpha.event')
        ->update(['action' => 'tampered']));
})->throws(QueryException::class, 'append-only');

function publishTenant(Organization $organization): void
{
    DB::statement(
        "SELECT set_config('app.organization_id', ?, false)",
        [$organization->getKey()],
    );
}
