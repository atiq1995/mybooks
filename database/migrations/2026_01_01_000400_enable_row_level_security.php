<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row-level security — the second tenant-isolation layer.
 *
 * The first layer is the global Eloquent scope in the application. This one
 * does not trust it. If a query is ever built without an organisation filter,
 * PostgreSQL returns zero rows rather than another company's books.
 *
 * How it holds:
 *
 *   - The application connects as `my_books_app`, which OWNS NOTHING. A table
 *     owner bypasses RLS in PostgreSQL; a non-owner cannot.
 *   - Migrations run on the `pgsql_owner` connection as the schema owner,
 *     which does bypass RLS — because a migration must.
 *   - Middleware sets `app.organization_id` and `app.user_id` on the session
 *     before any query runs.
 *   - No context means NO ROWS, not all rows.
 *
 * The context functions are defined here rather than only in the container
 * init script, because init scripts run once on an empty data directory and
 * a migration runs on every deployment. `CREATE OR REPLACE` makes this safe
 * whether or not the init script already created them.
 *
 * @see SECURITY.md §3
 */
return new class extends Migration
{
    /**
     * Tables owned by an organisation, and therefore subject to RLS.
     *
     * Every future migration that creates an organisation-scoped table must
     * add it here — there is a test that fails if a table with an
     * `organization_id` column has no policy.
     *
     * @var list<string>
     */
    private const array SCOPED_TABLES = [
        'audit_logs',
    ];

    public function up(): void
    {
        $this->defineContextFunctions();

        /*
         * organizations
         *
         * Not scoped to the current organisation, because a user must be able
         * to enumerate the organisations they belong to BEFORE choosing one —
         * the switcher query runs with no organisation context at all.
         * Membership is the access rule here.
         */
        DB::unprepared(<<<'SQL'
            ALTER TABLE organizations ENABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS organizations_tenant_isolation ON organizations;
            CREATE POLICY organizations_tenant_isolation ON organizations
                USING (
                    app_is_unscoped()
                    OR id = app_current_organization_id()
                    OR EXISTS (
                        SELECT 1
                        FROM organization_memberships m
                        WHERE m.organization_id = organizations.id
                          AND m.user_id = app_current_user_id()
                          AND m.status = 'active'
                    )
                );
        SQL);

        /*
         * organization_memberships
         *
         * Two legitimate readers: a user looking at their own memberships
         * across organisations (the switcher), and an administrator looking
         * at everyone's membership within the current organisation (the user
         * list). Both are expressed, and nothing else is.
         */
        DB::unprepared(<<<'SQL'
            ALTER TABLE organization_memberships ENABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS memberships_tenant_isolation ON organization_memberships;
            CREATE POLICY memberships_tenant_isolation ON organization_memberships
                USING (
                    app_is_unscoped()
                    OR user_id = app_current_user_id()
                    OR organization_id = app_current_organization_id()
                )
                WITH CHECK (
                    app_is_unscoped()
                    OR organization_id = app_current_organization_id()
                );
        SQL);

        // Straightforward organisation-owned tables.
        foreach (self::SCOPED_TABLES as $table) {
            $this->applyStandardPolicy($table);
        }
    }

    public function down(): void
    {
        foreach ([...self::SCOPED_TABLES, 'organization_memberships', 'organizations'] as $table) {
            DB::unprepared("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY;");
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS app_current_user_id();
            DROP FUNCTION IF EXISTS app_current_organization_id();
            DROP FUNCTION IF EXISTS app_is_unscoped();
        SQL);
    }

    /**
     * The plain "belongs to exactly one organisation" policy.
     *
     * WITH CHECK matters as much as USING: without it, a row could be read
     * back correctly but written with someone else's organisation_id.
     *
     * Only names from the SCOPED_TABLES constant reach this — hence
     * literal-string, which is what lets the identifier be interpolated.
     *
     * @param  literal-string  $table
     */
    private function applyStandardPolicy(string $table): void
    {
        $policy = "{$table}_tenant_isolation";

        DB::unprepared(<<<SQL
            ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS {$policy} ON {$table};
            CREATE POLICY {$policy} ON {$table}
                USING (
                    app_is_unscoped()
                    OR organization_id = app_current_organization_id()
                )
                WITH CHECK (
                    app_is_unscoped()
                    OR organization_id = app_current_organization_id()
                );
        SQL);
    }

    private function defineContextFunctions(): void
    {
        DB::unprepared(<<<'SQL'
            -- Current organisation, or NULL when no tenant context is set.
            -- The `true` second argument makes a missing setting return NULL
            -- rather than raising: a request outside tenant context must see
            -- nothing, not fail with a confusing error.
            CREATE OR REPLACE FUNCTION app_current_organization_id()
            RETURNS uuid
            LANGUAGE sql STABLE PARALLEL SAFE
            AS $$
                SELECT NULLIF(current_setting('app.organization_id', true), '')::uuid;
            $$;

            CREATE OR REPLACE FUNCTION app_current_user_id()
            RETURNS uuid
            LANGUAGE sql STABLE PARALLEL SAFE
            AS $$
                SELECT NULLIF(current_setting('app.user_id', true), '')::uuid;
            $$;

            -- Escape hatch for genuinely cross-tenant maintenance: the ledger
            -- verifier, backups, platform administration. Never set on a web
            -- request.
            CREATE OR REPLACE FUNCTION app_is_unscoped()
            RETURNS boolean
            LANGUAGE sql STABLE PARALLEL SAFE
            AS $$
                SELECT coalesce(current_setting('app.unscoped', true), 'off') = 'on';
            $$;
        SQL);
    }
};
