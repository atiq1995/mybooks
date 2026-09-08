<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Files attached to a record.
 *
 * Generic from the start, rather than an `expense_receipts` table that would
 * be copied for bills, then journals, then contacts. Every module wants the
 * same three things — attach, list, fetch — and the only part that differs is
 * what the file hangs off.
 *
 * The FILE lives in object storage; this table is the index. It carries the
 * facts the application needs without reaching for the object store: the
 * original name, the size, the type, and a checksum. The checksum is what
 * makes "this is the same receipt attached twice" answerable, which matters
 * because a duplicate receipt is usually a duplicate claim.
 *
 * The bucket is PRIVATE. Nothing here is served by URL from storage —
 * a receipt is a financial record, and an object store URL that leaks is a
 * document that leaks with no audit trail and no expiry. The application
 * streams the file through an authorised route instead.
 *
 * @see docs/adr/0002-multi-tenancy.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            /*
             * What this is attached to.
             *
             * A morph pair rather than one nullable foreign key per module.
             * There is no referential integrity behind it, which is the price
             * — so a deleted parent leaves an orphan, and the module that
             * deletes a parent is responsible for its attachments.
             */
            $table->string('attachable_type', 100);
            $table->uuid('attachable_id');

            // Where it sits in the bucket. Never shown to a user, never
            // guessable: the id is a UUID and the prefix is per organisation.
            $table->string('disk', 20)->default('s3');
            $table->string('path', 500);

            // What the user called it, for the download filename.
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');

            /*
             * SHA-256 of the contents.
             *
             * So "we already have this receipt" is answerable. A duplicate
             * receipt is usually a duplicate claim, and catching it at upload
             * is cheaper than catching it at audit.
             */
            $table->char('checksum', 64);

            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['organization_id', 'attachable_type', 'attachable_id']);
            // The duplicate lookup: the same file, on the same parent, twice.
            $table->index(['organization_id', 'checksum']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE attachments
                ADD CONSTRAINT attachments_size_positive
                    CHECK (size_bytes > 0),
                ADD CONSTRAINT attachments_path_not_blank
                    CHECK (btrim(path) <> ''),
                ADD CONSTRAINT attachments_checksum_hex
                    CHECK (checksum ~ '^[0-9a-f]{64}$');
        SQL);

        DB::statement('ALTER TABLE attachments ENABLE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY attachments_tenant_isolation ON attachments
                USING (app_is_unscoped() OR organization_id = app_current_organization_id())
                WITH CHECK (app_is_unscoped() OR organization_id = app_current_organization_id());
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
