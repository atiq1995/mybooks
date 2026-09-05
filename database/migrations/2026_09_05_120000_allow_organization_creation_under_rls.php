<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets an authenticated user create an organisation under row-level security.
 *
 * THE BUG
 * -------
 * The original `organizations_tenant_isolation` policy declared only a USING
 * clause. PostgreSQL falls back to the USING expression as the INSERT check
 * when no WITH CHECK is given — and USING grants visibility through
 * membership:
 *
 *     id = app_current_organization_id()
 *     OR EXISTS (an active membership for the current user)
 *
 * At the moment an organisation is created, neither holds. There is no active
 * organisation yet, and the owner's membership cannot exist because the
 * organisation it points at does not exist. So the INSERT was refused and
 * creating a set of books was impossible for real application traffic.
 *
 * WHY THE TESTS DID NOT CATCH IT
 * ------------------------------
 * The suite connects as the schema OWNER, which bypasses RLS by design so
 * fixtures can be built without a tenant context. Only traffic on the
 * `my_books_app` role — production, and the explicit SET ROLE tests — feels
 * this policy. A regression test that runs as the application role is added
 * alongside this migration.
 *
 * THE FIX
 * -------
 * An explicit WITH CHECK that permits exactly two writes:
 *
 *   - creating an organisation attributed to the acting user
 *     (`created_by = app_current_user_id()`), which is the creation case and
 *     also stops a row being inserted in somebody else's name
 *   - updating the organisation that is currently active
 *
 * Everything else is still refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
                )
                WITH CHECK (
                    app_is_unscoped()
                    -- Creating a new set of books, attributed to the actor.
                    OR created_by = app_current_user_id()
                    -- Editing the organisation that is currently active.
                    OR id = app_current_organization_id()
                );
        SQL);

        /*
         * Breaking a policy cycle.
         *
         * The memberships policy needs to know who created an organisation, but
         * reading `organizations` from inside a policy re-enters the
         * organizations policy, which in turn reads `organization_memberships`.
         * PostgreSQL detects that structurally and refuses the whole statement
         * with "infinite recursion detected in policy".
         *
         * SECURITY DEFINER runs as the function's owner — the schema owner,
         * which bypasses RLS — so the lookup does not re-enter any policy.
         * It is deliberately narrow: one column, for one row, by primary key.
         * Knowing an organisation's creator requires already knowing its UUID.
         *
         * search_path is pinned, because a SECURITY DEFINER function with a
         * caller-controlled search_path is a privilege-escalation primitive.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_organization_creator(org uuid)
            RETURNS uuid
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
                SELECT created_by FROM organizations WHERE id = org;
            $$;

            COMMENT ON FUNCTION app_organization_creator(uuid) IS
                'Who created an organisation, read without invoking RLS. Exists solely to break the policy cycle between organizations and organization_memberships.';
        SQL);

        /*
         * The owner's membership is written immediately after the organisation,
         * inside the same transaction — before any tenant context points at the
         * new organisation. The memberships policy has to allow that first row,
         * otherwise the organisation is created and instantly orphaned.
         *
         * Restricted to a membership the acting user is granting to THEMSELVES
         * in an organisation THEY created; adding other people goes through the
         * invitation flow, under an active tenant context.
         */
        DB::unprepared(<<<'SQL'
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
                    OR (
                        user_id = app_current_user_id()
                        AND app_organization_creator(organization_id) = app_current_user_id()
                    )
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
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

            DROP FUNCTION IF EXISTS app_organization_creator(uuid);
        SQL);
    }
};
