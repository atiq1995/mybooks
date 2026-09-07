<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets a signed-out visitor find the invitation they were sent.
 *
 * THE PROBLEM
 * -----------
 * Someone opening an invitation link has no account, no session and no tenant
 * context — that is the entire point of an invitation. The memberships policy
 * admits rows by `user_id = app_current_user_id()` or
 * `organization_id = app_current_organization_id()`, and for this visitor both
 * settings are empty. So row-level security correctly hid the invitation, and
 * every link reported itself as invalid.
 *
 * THE OPTIONS
 * -----------
 * Reading it on the schema-owner connection would work, but that bypasses RLS
 * for the whole query and erodes the two-layer discipline for a case that does
 * not need it. Lifting RLS inside `runUnscoped()` would be worse still: it
 * would make an application-layer escape hatch silently disable the database
 * layer everywhere it is used.
 *
 * THE FIX
 * -------
 * Possession of the token IS the authorisation, exactly as with a password
 * reset link. So the application publishes the token's HASH as a setting and a
 * policy admits the one row that matches it. Nothing else becomes visible, the
 * plaintext token never reaches the database, and a visitor who guesses no
 * token sees nothing.
 *
 * SELECT only — accepting the invitation writes under a real tenant context.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Permissive policies are OR'd together, so this widens visibility
            -- for exactly one row and changes nothing else.
            CREATE POLICY memberships_invitation_lookup ON organization_memberships
                FOR SELECT
                USING (
                    invitation_token_hash IS NOT NULL
                    AND invitation_token_hash = NULLIF(
                        current_setting('app.invitation_token_hash', true), ''
                    )
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(
            'DROP POLICY IF EXISTS memberships_invitation_lookup ON organization_memberships;'
        );
    }
};
