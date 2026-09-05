<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail.
 *
 * Written in the SAME TRANSACTION as the change it describes — an audit
 * record that can be committed separately is an audit record that can be
 * lost, and the moments it would be lost are exactly the interesting ones.
 *
 * Rows here are immutable and never purged. Retention is a legal obligation,
 * not a storage preference. Enforced by trigger, not by convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id')->nullable();

            // -- Who -----------------------------------------------------
            // Nullable because some actions have no user: the scheduler
            // generating a recurring invoice, a webhook, a console command.
            $table->uuid('user_id')->nullable();

            // Denormalised on purpose. If the user is later deleted, the
            // audit trail must still say who did this.
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();

            // 'user' | 'system' | 'api' | 'schedule' | 'console'
            $table->string('actor_type', 20)->default('user');

            // -- What ----------------------------------------------------
            // Dotted verb: 'invoice.posted', 'period.closed', 'role.changed'.
            $table->string('action', 100);

            $table->string('auditable_type')->nullable();
            $table->uuid('auditable_id')->nullable();

            // Human-readable at the time of the event, so a log entry still
            // reads correctly after the subject is renamed or voided.
            $table->string('description')->nullable();

            // -- Change ---------------------------------------------------
            // Only the attributes that actually changed. Never a password,
            // token or secret — the recorder redacts before writing.
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();

            // -- Context --------------------------------------------------
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Ties an audit row to the request that produced it, and to the
            // application log lines from that same request.
            $table->uuid('request_id')->nullable();

            // 'web' | 'api' | 'console' | 'queue'
            $table->string('channel', 20)->default('web');

            // Financial events carry the amount for fast filtering, so a
            // reviewer can ask "show me everything over 1,000,000" without
            // joining out to each document type.
            $table->decimal('amount', 19, 4)->nullable();
            $table->char('currency', 3)->nullable();

            // No updated_at. These rows are never updated.
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            // The three questions actually asked of an audit log:
            // "what happened to this record", "what did this person do",
            // "what happened in this organisation lately".
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'user_id', 'created_at']);
            $table->index(['organization_id', 'action', 'created_at']);
            $table->index('request_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE audit_logs
                ADD CONSTRAINT audit_logs_actor_type_known
                    CHECK (actor_type IN ('user','system','api','schedule','console')),
                ADD CONSTRAINT audit_logs_channel_known
                    CHECK (channel IN ('web','api','console','queue')),
                ADD CONSTRAINT audit_logs_currency_format
                    CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$')
        SQL);

        /*
         * Immutability.
         *
         * An audit trail that the application can rewrite proves nothing
         * about the application. This trigger means that even a compromised
         * application role, or a mistaken migration, cannot quietly alter
         * history — the write is refused by the database itself.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_are_immutable()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'audit_logs is append-only: % on audit_logs is not permitted', TG_OP
                    USING HINT = 'Audit records are never corrected. Record a new event instead.',
                          ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_are_immutable();

            CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_are_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs;
            DROP TRIGGER IF EXISTS audit_logs_no_delete ON audit_logs;
            DROP FUNCTION IF EXISTS audit_logs_are_immutable();
        SQL);

        Schema::dropIfExists('audit_logs');
    }
};
