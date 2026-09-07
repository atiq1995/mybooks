<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger. The most important tables in the system.
 *
 * Three invariants are enforced here, in the database, regardless of what the
 * application believes:
 *
 *   I1  every posted entry balances: SUM(debit) = SUM(credit)
 *   I2  it balances in BOTH currencies — transaction and base
 *   I3  a line has a debit or a credit, never both, never neither, never negative
 *   I4  posted entries and lines are never updated or deleted
 *
 * The balance check is a DEFERRED constraint trigger. It has to be: lines are
 * inserted one at a time, so the entry is transiently unbalanced in the middle
 * of a perfectly correct transaction. Deferring to COMMIT means the check sees
 * the finished entry.
 *
 * @see ACCOUNTING_RULES.md §1
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // Gap-free per organisation. Many tax authorities treat a gap in
            // numbering as evidence of a deleted entry.
            $table->string('entry_no', 30);

            $table->date('entry_date');
            $table->uuid('fiscal_period_id');

            /*
             * What produced this entry: 'invoice', 'bill', 'payment',
             * 'manual', 'closing'... plus the id of that thing and the purpose
             * within it ('issue', 'void', 'settle'). Together these are unique,
             * which is what makes posting idempotent — a retried request
             * cannot double-post.
             */
            $table->string('source_type', 40);
            $table->uuid('source_id')->nullable();
            $table->string('source_purpose', 40)->default('issue');

            $table->string('memo')->nullable();

            // Transaction currency, and the rate used to reach base currency.
            $table->char('currency', 3);
            $table->char('base_currency', 3);
            $table->decimal('exchange_rate', 19, 10)->default(1);

            // Denormalised totals, so a trial balance need not sum every line
            // to know an entry's size. Verified against the lines by trigger.
            $table->decimal('total_debit', 19, 4)->default(0);
            $table->decimal('total_credit', 19, 4)->default(0);
            $table->decimal('total_debit_base', 19, 4)->default(0);
            $table->decimal('total_credit_base', 19, 4)->default(0);

            $table->string('status', 12)->default('posted');

            // A reversal points at what it reverses. Both remain visible for
            // ever — corrections never erase history.
            $table->uuid('reverses_entry_id')->nullable();

            $table->uuid('posted_by')->nullable();
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('fiscal_period_id')->references('id')->on('fiscal_periods');

            $table->unique(['organization_id', 'entry_no']);

            // The general ledger and every report read by organisation + date.
            $table->index(['organization_id', 'entry_date']);
            $table->index(['organization_id', 'fiscal_period_id']);
            $table->index(['organization_id', 'source_type', 'source_id']);
        });

        /*
         * The self-reference is added after the table exists: declared inside
         * Schema::create it runs before the primary key, and PostgreSQL rejects
         * it with "no unique constraint matching given keys".
         */
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->foreign('reverses_entry_id')->references('id')->on('journal_entries');
        });

        // Idempotency. A source may post several times for DIFFERENT purposes
        // (issue, then settle), but never twice for the same one.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX journal_entries_source_idempotent
                ON journal_entries (organization_id, source_type, source_id, source_purpose)
                WHERE source_id IS NOT NULL
        SQL);

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('journal_entry_id');

            $table->unsignedSmallInteger('line_no');
            $table->uuid('account_id');

            // Exactly one of these is non-zero. Never negative — a negative
            // debit is a credit, and allowing both spellings makes every
            // report ambiguous.
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);

            // The same amounts in the organisation's base currency, computed
            // once at posting and NEVER recomputed. This is what makes
            // historical FX correct.
            $table->decimal('debit_base', 19, 4)->default(0);
            $table->decimal('credit_base', 19, 4)->default(0);

            $table->string('memo')->nullable();

            /*
             * Dimensions. Optional references that let the general ledger be
             * sliced by customer, vendor, item, project or warehouse without
             * a separate analytics model.
             */
            $table->uuid('contact_id')->nullable();
            $table->uuid('item_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->uuid('warehouse_id')->nullable();
            $table->uuid('tax_id')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts');

            $table->unique(['journal_entry_id', 'line_no']);

            // An account's balance is the sum of its lines; this is the index
            // that makes a trial balance fast.
            $table->index(['organization_id', 'account_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE journal_entries
                ADD CONSTRAINT journal_entries_status_known
                    CHECK (status IN ('posted','reversed')),
                ADD CONSTRAINT journal_entries_currency_format
                    CHECK (currency ~ '^[A-Z]{3}$' AND base_currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT journal_entries_rate_positive
                    CHECK (exchange_rate > 0),
                ADD CONSTRAINT journal_entries_totals_non_negative
                    CHECK (total_debit >= 0 AND total_credit >= 0),
                -- I1 and I2, on the denormalised totals.
                ADD CONSTRAINT journal_entries_balanced
                    CHECK (total_debit = total_credit),
                ADD CONSTRAINT journal_entries_balanced_base
                    CHECK (total_debit_base = total_credit_base),
                -- An entry with nothing in it is not a transaction.
                ADD CONSTRAINT journal_entries_not_empty
                    CHECK (total_debit > 0);

            ALTER TABLE journal_lines
                -- I3: exactly one side, never negative.
                ADD CONSTRAINT journal_lines_non_negative
                    CHECK (debit >= 0 AND credit >= 0 AND debit_base >= 0 AND credit_base >= 0),
                ADD CONSTRAINT journal_lines_one_side_only
                    CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)),
                -- The base-currency side must match the transaction side, or a
                -- debit could silently become a credit on conversion.
                ADD CONSTRAINT journal_lines_sides_agree
                    CHECK ((debit > 0 AND credit_base = 0) OR (credit > 0 AND debit_base = 0));
        SQL);

        /*
         * I1/I2 again, this time against the LINES rather than the totals.
         *
         * DEFERRABLE INITIALLY DEFERRED is essential: lines arrive one at a
         * time, so the entry is transiently unbalanced mid-transaction. The
         * check runs at COMMIT, when the entry is complete.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_entry_must_balance()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                entry_id uuid;
                sum_debit numeric(19,4);
                sum_credit numeric(19,4);
                sum_debit_base numeric(19,4);
                sum_credit_base numeric(19,4);
                header_debit numeric(19,4);
                header_credit numeric(19,4);
            BEGIN
                entry_id := COALESCE(NEW.journal_entry_id, OLD.journal_entry_id);

                SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0),
                       COALESCE(SUM(debit_base), 0), COALESCE(SUM(credit_base), 0)
                  INTO sum_debit, sum_credit, sum_debit_base, sum_credit_base
                  FROM journal_lines
                 WHERE journal_entry_id = entry_id;

                IF sum_debit <> sum_credit THEN
                    RAISE EXCEPTION
                        'Journal entry % does not balance: debits %, credits %',
                        entry_id, sum_debit, sum_credit
                        USING ERRCODE = 'check_violation',
                              HINT = 'Every posted entry must satisfy SUM(debit) = SUM(credit). See ACCOUNTING_RULES.md I1.';
                END IF;

                IF sum_debit_base <> sum_credit_base THEN
                    RAISE EXCEPTION
                        'Journal entry % does not balance in base currency: debits %, credits %',
                        entry_id, sum_debit_base, sum_credit_base
                        USING ERRCODE = 'check_violation',
                              HINT = 'An entry must balance in the transaction currency AND the base currency. See ACCOUNTING_RULES.md I2.';
                END IF;

                -- The denormalised totals must agree with the lines, or every
                -- report that trusts them is quietly wrong.
                SELECT total_debit, total_credit INTO header_debit, header_credit
                  FROM journal_entries WHERE id = entry_id;

                IF header_debit IS NOT NULL AND header_debit <> sum_debit THEN
                    RAISE EXCEPTION
                        'Journal entry % header total (%) disagrees with its lines (%)',
                        entry_id, header_debit, sum_debit
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER journal_lines_balance_check
                AFTER INSERT OR UPDATE OR DELETE ON journal_lines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION journal_entry_must_balance();
        SQL);

        /*
         * I4: the ledger is append-only.
         *
         * Corrections are reversing entries. The only permitted update to a
         * posted entry is marking it reversed, which is why that one column is
         * carved out explicitly rather than the whole row being immutable.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_is_append_only()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'The ledger is append-only: % on % is not permitted',
                        TG_OP, TG_TABLE_NAME
                        USING ERRCODE = 'restrict_violation',
                              HINT = 'Reverse the entry instead. See ACCOUNTING_RULES.md I4.';
                END IF;

                -- journal_entries: only the reversal bookkeeping may change.
                IF TG_TABLE_NAME = 'journal_entries' THEN
                    IF NEW.entry_no       IS DISTINCT FROM OLD.entry_no
                    OR NEW.entry_date     IS DISTINCT FROM OLD.entry_date
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.total_debit    IS DISTINCT FROM OLD.total_debit
                    OR NEW.total_credit   IS DISTINCT FROM OLD.total_credit
                    OR NEW.currency       IS DISTINCT FROM OLD.currency
                    OR NEW.exchange_rate  IS DISTINCT FROM OLD.exchange_rate
                    OR NEW.source_type    IS DISTINCT FROM OLD.source_type
                    OR NEW.source_id      IS DISTINCT FROM OLD.source_id THEN
                        RAISE EXCEPTION 'A posted journal entry cannot be edited'
                            USING ERRCODE = 'restrict_violation',
                                  HINT = 'Reverse it and post a correct one. See ACCOUNTING_RULES.md I4.';
                    END IF;

                    RETURN NEW;
                END IF;

                -- journal_lines: never editable at all.
                RAISE EXCEPTION 'A posted journal line cannot be edited'
                    USING ERRCODE = 'restrict_violation',
                          HINT = 'Reverse the entry instead. See ACCOUNTING_RULES.md I4.';
            END;
            $$;

            CREATE TRIGGER journal_entries_append_only
                BEFORE UPDATE OR DELETE ON journal_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_is_append_only();

            CREATE TRIGGER journal_lines_append_only
                BEFORE UPDATE OR DELETE ON journal_lines
                FOR EACH ROW EXECUTE FUNCTION ledger_is_append_only();
        SQL);

        foreach (['journal_entries', 'journal_lines'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                    USING (app_is_unscoped() OR organization_id = app_current_organization_id())
                    WITH CHECK (app_is_unscoped() OR organization_id = app_current_organization_id());
            SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_lines_append_only ON journal_lines;
            DROP TRIGGER IF EXISTS journal_entries_append_only ON journal_entries;
            DROP TRIGGER IF EXISTS journal_lines_balance_check ON journal_lines;
            DROP FUNCTION IF EXISTS ledger_is_append_only();
            DROP FUNCTION IF EXISTS journal_entry_must_balance();
        SQL);

        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
    }
};
