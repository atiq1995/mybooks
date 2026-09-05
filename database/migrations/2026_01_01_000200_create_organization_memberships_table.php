<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membership of a user in an organisation, with the role held there.
 *
 * Roles are per-organisation on purpose: the same person may be an
 * accountant in one set of books and read-only in another. A global role
 * would make that impossible to express and easy to get wrong.
 *
 * The permission catalogue itself lives in code (config/permissions.php), not
 * in this table. A permission name that does not exist should be a failing
 * test, not a silently ungranted capability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->uuid('user_id');

            // One of the roles defined in config/permissions.php.
            $table->string('role', 40);

            /*
             * Grants and revocations layered on top of the role, for the real
             * situations a fixed role set never quite covers — "the office
             * manager may create bills but not approve payments".
             *
             * Deliberately additive/subtractive rather than a replacement
             * permission list: an override that replaces the role entirely
             * stops tracking changes to that role over time.
             */
            $table->jsonb('granted_permissions')->nullable();
            $table->jsonb('revoked_permissions')->nullable();

            // -- Invitation lifecycle ----------------------------------
            $table->string('status', 20)->default('active');
            $table->uuid('invited_by')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();

            // Invitation tokens are stored hashed. A leaked database backup
            // must not hand out working invitations.
            $table->string('invitation_token_hash', 64)->nullable()->unique();
            $table->timestamp('invitation_expires_at')->nullable();

            $table->timestamp('last_accessed_at')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('invited_by')->references('id')->on('users')->nullOnDelete();

            // A user holds exactly one role per organisation.
            $table->unique(['organization_id', 'user_id']);

            $table->index(['user_id', 'status']);
            $table->index(['organization_id', 'role']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE organization_memberships
                ADD CONSTRAINT organization_memberships_status_known
                    CHECK (status IN ('active','invited','suspended')),
                -- An active membership must record when it began; an
                -- invitation must record when it expires. Without these the
                -- table accumulates rows nobody can reason about.
                ADD CONSTRAINT organization_memberships_active_has_joined
                    CHECK (status <> 'active' OR joined_at IS NOT NULL),
                ADD CONSTRAINT organization_memberships_invited_has_expiry
                    CHECK (status <> 'invited' OR invitation_expires_at IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_memberships');
    }
};
