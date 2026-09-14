<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Banking: accounts, imported statements, matches, reconciliations, transfers.
 *
 * Two ideas run through every table here.
 *
 * **A statement is not the ledger.** An imported line is somebody else's
 * record of what happened — the bank's. It has no accounting effect at all
 * until a human says which of OUR entries it corresponds to. So statement
 * lines live in their own tables, and the only thing that connects the two
 * worlds is a match row, written by a person with `banking.reconcile`.
 * §8's table says it plainly: import is not posting, and a match posts
 * nothing either — it asserts that something already posted has now cleared.
 *
 * **A completed reconciliation is a record, not a working document.** The
 * ledger is already append-only, so the entries cannot move. What could move
 * is the reconciliation ITSELF — unmatching a line, editing a closing
 * balance, deleting a statement row — and that would turn "these books were
 * reconciled to zero on 30 June" into a claim nobody can check. The triggers
 * below refuse it. Correcting a completed reconciliation means reversing in
 * the ledger and reconciling the reversal in a later period, exactly as
 * correcting a posted entry does.
 *
 * @see ACCOUNTING_RULES.md §4.9, §8
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A bank account is a PROFILE attached to a ledger account, not a
         * second account.
         *
         * The balance lives in the chart of accounts like every other
         * balance — a bank account with its own running total would be a
         * second source of truth about the same money, and the two would
         * disagree the first time something posted around it. What is kept
         * here is only what the ledger has no column for: which bank, which
         * (masked) number, and whether it is still in use.
         */
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->uuid('account_id');

            $table->string('name', 120);
            $table->string('bank_name', 120)->nullable();

            /*
             * The last few digits only, and it is the only form we ever
             * store. A full account number is a payment instruction: it is
             * not needed to reconcile anything, and holding it would put it
             * in every backup, export and log for no benefit at all.
             */
            $table->string('account_number_masked', 24)->nullable();
            $table->string('branch', 120)->nullable();

            // 'bank' | 'cash' | 'credit_card'
            $table->string('kind', 16)->default('bank');

            $table->char('currency', 3);

            $table->boolean('is_active')->default(true);
            $table->boolean('is_primary')->default(false);

            $table->text('notes')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts');

            // One profile per ledger account. Two would mean two answers to
            // "which bank is 1020?".
            $table->unique('account_id');
            $table->index(['organization_id', 'is_active']);
        });

        /*
         * One import of one file.
         *
         * Kept even when every row in it turned out to be a duplicate: "I
         * imported June twice and nothing happened" is a question people ask,
         * and the answer has to be visible somewhere.
         */
        Schema::create('bank_statement_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('bank_account_id');

            // 'csv' | 'ofx' | 'qif'
            $table->string('format', 8);
            $table->string('filename', 255);
            // Of the file's bytes, so re-uploading the identical file is
            // recognisable as such rather than merely producing duplicates.
            $table->char('file_hash', 64);

            $table->date('statement_start')->nullable();
            $table->date('statement_end')->nullable();

            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('rows_duplicate')->default(0);

            $table->uuid('imported_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->cascadeOnDelete();
            $table->foreign('imported_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['organization_id', 'bank_account_id', 'created_at']);
        });

        /*
         * One line of a statement, as the bank stated it.
         *
         * `amount` is SIGNED — positive is money in, negative is money out —
         * because that is how the formats express it, and splitting it into
         * two columns here would leave each parser deciding the convention
         * separately.
         *
         * `fingerprint` is what makes importing the same file twice harmless.
         * It hashes the line's content plus its occurrence number within the
         * account, so two genuinely identical transactions on the same day
         * both survive while a re-imported file collides on every row.
         */
        Schema::create('bank_statement_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('bank_account_id');
            $table->uuid('import_id');

            $table->date('transaction_date');
            $table->string('description', 500);
            $table->string('reference', 160)->nullable();
            $table->string('payee', 200)->nullable();

            $table->decimal('amount', 19, 4);
            // What the bank said the balance was afterwards, where the format
            // carries it. Never computed from our side.
            $table->decimal('statement_balance', 19, 4)->nullable();

            $table->char('fingerprint', 64);

            // 'unmatched' | 'matched' | 'excluded'
            $table->string('status', 12)->default('unmatched');
            $table->string('excluded_reason', 255)->nullable();

            /*
             * Set when a reconciliation completes. Non-null means locked: the
             * triggers below refuse every further change to this row.
             */
            $table->uuid('reconciliation_id')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->cascadeOnDelete();
            $table->foreign('import_id')->references('id')->on('bank_statement_imports')->cascadeOnDelete();

            $table->unique(['bank_account_id', 'fingerprint']);
            $table->index(['organization_id', 'bank_account_id', 'transaction_date']);
            $table->index(['organization_id', 'bank_account_id', 'status']);
        });

        /*
         * A reconciliation of one account over one period.
         *
         * `cleared_balance` is ours — opening plus everything matched up to
         * the period end. `closing_balance` is the bank's, typed in from the
         * statement. The difference between them is the entire point of the
         * exercise, and it must be zero before the reconciliation can be
         * completed.
         */
        Schema::create('bank_reconciliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('bank_account_id');

            $table->string('number', 30);

            $table->date('period_start');
            $table->date('period_end');

            $table->decimal('opening_balance', 19, 4)->default(0);
            $table->decimal('closing_balance', 19, 4)->default(0);
            $table->decimal('cleared_balance', 19, 4)->default(0);
            $table->decimal('difference', 19, 4)->default(0);

            // 'draft' | 'completed'
            $table->string('status', 12)->default('draft');

            $table->text('notes')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->cascadeOnDelete();
            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'bank_account_id', 'period_end']);
        });

        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->foreign('reconciliation_id')->references('id')->on('bank_reconciliations');
        });

        /*
         * The one place the bank's world and ours are joined.
         *
         * A match asserts that a posted journal line on this bank account is
         * the same event as a statement line. It posts nothing: both sides
         * already exist. The unique index on `journal_line_id` is what stops
         * one payment clearing two statement lines, which is how a
         * reconciliation can otherwise be forced to zero while being wrong.
         */
        Schema::create('bank_transaction_matches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('bank_account_id');

            $table->uuid('statement_line_id');
            $table->uuid('journal_line_id');

            // Signed like the statement line: positive is money in.
            $table->decimal('amount', 19, 4);

            /*
             * How this match came about. 'suggested' means a human accepted a
             * suggestion, 'manual' means they chose it themselves — never
             * 'automatic', because nothing here happens automatically.
             */
            $table->string('origin', 12)->default('manual');
            $table->unsignedSmallInteger('confidence')->nullable();

            $table->uuid('reconciliation_id')->nullable();

            $table->string('note', 255)->nullable();

            $table->uuid('matched_by')->nullable();
            $table->timestamp('matched_at');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->cascadeOnDelete();
            $table->foreign('statement_line_id')->references('id')->on('bank_statement_lines')->cascadeOnDelete();
            $table->foreign('journal_line_id')->references('id')->on('journal_lines');
            $table->foreign('reconciliation_id')->references('id')->on('bank_reconciliations');
            $table->foreign('matched_by')->references('id')->on('users')->nullOnDelete();

            // A ledger line clears once.
            $table->unique('journal_line_id');
            $table->index(['organization_id', 'bank_account_id', 'reconciliation_id']);
        });

        /*
         * Money moved between two of our own accounts.
         *
         * §4.9: never income or expense on either side. Where the two
         * accounts are in different currencies, both amounts are recorded as
         * they actually happened and the difference in base currency is an FX
         * gain or loss — the same treatment as a settlement at a different
         * rate, and for the same reason.
         */
        Schema::create('bank_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->string('number', 30);
            $table->date('transfer_date');

            $table->uuid('from_account_id');
            $table->uuid('to_account_id');

            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->decimal('exchange_rate', 19, 10)->default(1);

            $table->char('destination_currency', 3);
            $table->decimal('amount_received', 19, 4);
            $table->decimal('destination_exchange_rate', 19, 10)->default(1);

            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();

            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('void_journal_entry_id')->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('from_account_id')->references('id')->on('accounts');
            $table->foreign('to_account_id')->references('id')->on('accounts');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries');
            $table->foreign('void_journal_entry_id')->references('id')->on('journal_entries');

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'transfer_date']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE bank_accounts
                ADD CONSTRAINT bank_accounts_kind_known
                    CHECK (kind IN ('bank','cash','credit_card')),
                ADD CONSTRAINT bank_accounts_currency_format
                    CHECK (currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT bank_accounts_name_not_blank
                    CHECK (btrim(name) <> '');

            ALTER TABLE bank_statement_imports
                ADD CONSTRAINT bank_statement_imports_format_known
                    CHECK (format IN ('csv','ofx','qif')),
                ADD CONSTRAINT bank_statement_imports_period_ordered
                    CHECK (statement_end IS NULL OR statement_start IS NULL
                        OR statement_end >= statement_start),
                ADD CONSTRAINT bank_statement_imports_counts_add_up
                    CHECK (rows_imported + rows_duplicate <= rows_total);

            ALTER TABLE bank_statement_lines
                ADD CONSTRAINT bank_statement_lines_status_known
                    CHECK (status IN ('unmatched','matched','excluded')),
                /*
                 * A zero-amount statement line is not a transaction. Every
                 * format produces them occasionally as headers or markers,
                 * and importing one would hand the matcher something that can
                 * never be matched to anything.
                 */
                ADD CONSTRAINT bank_statement_lines_amount_not_zero
                    CHECK (amount <> 0),
                ADD CONSTRAINT bank_statement_lines_description_not_blank
                    CHECK (btrim(description) <> ''),
                -- Excluding a line is a decision, and a decision needs a
                -- reason somebody can read a year later.
                ADD CONSTRAINT bank_statement_lines_exclusion_has_reason
                    CHECK (status <> 'excluded' OR btrim(coalesce(excluded_reason, '')) <> '');

            ALTER TABLE bank_reconciliations
                ADD CONSTRAINT bank_reconciliations_status_known
                    CHECK (status IN ('draft','completed')),
                ADD CONSTRAINT bank_reconciliations_period_ordered
                    CHECK (period_end >= period_start),
                /*
                 * The exit criterion, as a constraint: a completed
                 * reconciliation reconciles to zero and says who completed
                 * it. "Completed with a difference of 300" is not a
                 * reconciliation, it is an unfinished one with a nicer word
                 * on it.
                 */
                ADD CONSTRAINT bank_reconciliations_completed_is_reconciled
                    CHECK (status <> 'completed'
                        OR (difference = 0 AND completed_at IS NOT NULL AND completed_by IS NOT NULL)),
                ADD CONSTRAINT bank_reconciliations_difference_is_derived
                    CHECK (difference = closing_balance - cleared_balance);

            ALTER TABLE bank_transaction_matches
                ADD CONSTRAINT bank_transaction_matches_origin_known
                    CHECK (origin IN ('manual','suggested')),
                ADD CONSTRAINT bank_transaction_matches_amount_not_zero
                    CHECK (amount <> 0),
                ADD CONSTRAINT bank_transaction_matches_confidence_range
                    CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 100);

            ALTER TABLE bank_transfers
                ADD CONSTRAINT bank_transfers_amount_positive
                    CHECK (amount > 0 AND amount_received > 0),
                ADD CONSTRAINT bank_transfers_rates_positive
                    CHECK (exchange_rate > 0 AND destination_exchange_rate > 0),
                ADD CONSTRAINT bank_transfers_currency_format
                    CHECK (currency ~ '^[A-Z]{3}$' AND destination_currency ~ '^[A-Z]{3}$'),
                /*
                 * Money cannot move from an account to itself. It looks like
                 * a harmless no-op, posts a debit and a credit to the same
                 * account, and is invisible in every report afterwards.
                 */
                ADD CONSTRAINT bank_transfers_accounts_differ
                    CHECK (from_account_id <> to_account_id);
        SQL);

        // A transfer posts once, and once more when voided.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX bank_transfers_journal_entry_unique
                ON bank_transfers (journal_entry_id)
                WHERE journal_entry_id IS NOT NULL
        SQL);

        /*
         * One reconciliation in progress per account.
         *
         * Two open at once would each see the other's matches, and both would
         * reach a different answer about what has cleared.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX bank_reconciliations_one_draft_per_account
                ON bank_reconciliations (bank_account_id)
                WHERE status = 'draft'
        SQL);

        /*
         * A completed reconciliation is frozen — it, its statement lines and
         * its matches.
         *
         * This is the "cannot be silently altered" half of the exit
         * criterion, and it lives here rather than in PHP because a
         * controller cannot be the guarantee: a console command, a future
         * import path or a stray `->update()` would each bypass it.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bank_reconciliation_is_frozen()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.reconciliation_id IS NOT NULL THEN
                    RAISE EXCEPTION
                        'Reconciliation % is completed: its statement lines and matches are a record and cannot be changed. Reverse in the ledger and reconcile the reversal in a later period.',
                        OLD.reconciliation_id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN CASE TG_OP WHEN 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;

            CREATE TRIGGER bank_statement_lines_frozen_when_reconciled
                BEFORE UPDATE OR DELETE ON bank_statement_lines
                FOR EACH ROW EXECUTE FUNCTION bank_reconciliation_is_frozen();

            CREATE TRIGGER bank_transaction_matches_frozen_when_reconciled
                BEFORE UPDATE OR DELETE ON bank_transaction_matches
                FOR EACH ROW EXECUTE FUNCTION bank_reconciliation_is_frozen();

            CREATE OR REPLACE FUNCTION bank_reconciliation_is_final()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.status = 'completed' THEN
                    RAISE EXCEPTION
                        'Reconciliation % is completed and cannot be reopened or deleted.',
                        OLD.number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN CASE TG_OP WHEN 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;

            CREATE TRIGGER bank_reconciliations_final_when_completed
                BEFORE UPDATE OR DELETE ON bank_reconciliations
                FOR EACH ROW EXECUTE FUNCTION bank_reconciliation_is_final();
        SQL);

        foreach ([
            'bank_accounts',
            'bank_statement_imports',
            'bank_statement_lines',
            'bank_reconciliations',
            'bank_transaction_matches',
            'bank_transfers',
        ] as $tenantTable) {
            DB::statement("ALTER TABLE {$tenantTable} ENABLE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY {$tenantTable}_tenant_isolation ON {$tenantTable}
                    USING (app_is_unscoped() OR organization_id = app_current_organization_id())
                    WITH CHECK (app_is_unscoped() OR organization_id = app_current_organization_id());
            SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS bank_reconciliations_final_when_completed ON bank_reconciliations;
            DROP TRIGGER IF EXISTS bank_transaction_matches_frozen_when_reconciled ON bank_transaction_matches;
            DROP TRIGGER IF EXISTS bank_statement_lines_frozen_when_reconciled ON bank_statement_lines;
            DROP FUNCTION IF EXISTS bank_reconciliation_is_final();
            DROP FUNCTION IF EXISTS bank_reconciliation_is_frozen();
        SQL);

        Schema::dropIfExists('bank_transfers');
        Schema::dropIfExists('bank_transaction_matches');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('bank_statement_imports');
        Schema::dropIfExists('bank_accounts');
    }
};
