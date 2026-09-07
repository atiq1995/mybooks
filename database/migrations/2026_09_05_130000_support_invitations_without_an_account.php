<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an invitation exist before its recipient has an account.
 *
 * Open registration is off, so an invitation is the ONLY route to an account
 * in this deployment. That means the common case is inviting somebody who has
 * never signed in — at which point there is no `user_id` to point at, and the
 * email on the invitation is the only thing linking it to a person.
 *
 * So `user_id` becomes nullable and `invited_email` is added. Exactly one of
 * them must be present, and a row acquires its `user_id` when the invitation
 * is accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_memberships', function (Blueprint $table): void {
            // The address the invitation was sent to. Retained after
            // acceptance so the audit trail records who was invited, even if
            // the person later changes their account email.
            $table->string('invited_email')->nullable()->after('user_id');
        });

        DB::statement('ALTER TABLE organization_memberships ALTER COLUMN user_id DROP NOT NULL');

        /*
         * The original unique index was (organization_id, user_id). PostgreSQL
         * treats NULLs as distinct, so that no longer prevents two pending
         * invitations to the same address. A partial unique index on the email
         * covers the invitation case, and the original still covers members.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX organization_memberships_pending_email_unique
                ON organization_memberships (organization_id, lower(invited_email))
                WHERE invited_email IS NOT NULL AND user_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE organization_memberships
                -- A membership identifies its person by account or by the
                -- address they were invited at. Never by neither.
                ADD CONSTRAINT organization_memberships_has_a_person
                    CHECK (user_id IS NOT NULL OR invited_email IS NOT NULL),

                -- Only a pending invitation may lack an account.
                ADD CONSTRAINT organization_memberships_accepted_has_user
                    CHECK (status = 'invited' OR user_id IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE organization_memberships
                DROP CONSTRAINT IF EXISTS organization_memberships_has_a_person,
                DROP CONSTRAINT IF EXISTS organization_memberships_accepted_has_user
        SQL);

        DB::statement('DROP INDEX IF EXISTS organization_memberships_pending_email_unique');

        // Rows without an account cannot survive a NOT NULL user_id.
        DB::statement('DELETE FROM organization_memberships WHERE user_id IS NULL');
        DB::statement('ALTER TABLE organization_memberships ALTER COLUMN user_id SET NOT NULL');

        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->dropColumn('invited_email');
        });
    }
};
