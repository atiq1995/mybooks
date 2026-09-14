<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let a paused recurring invoice keep its place.
 *
 * The original constraint said `(status = 'active') = (next_run_on IS NOT
 * NULL)`, which forbade exactly the behaviour pausing exists for. Pausing is
 * not ending: a retainer paused for two months and then resumed still owes
 * those months, and the catch-up bills them — which it can only do if the
 * template remembers where it had got to.
 *
 * The invariant that was actually meant is about ENDING. A schedule that has
 * run out has nothing left to run, and one that has something left has not
 * run out. Paused sits in between and keeps its date.
 *
 * Forward-only, like every other correction here: the wrong constraint is
 * dropped and the right one added, rather than the original migration being
 * edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE recurring_invoices
                DROP CONSTRAINT IF EXISTS recurring_invoices_active_has_next_run,
                -- Ended means finished, and finished means there is nothing
                -- left to run. Active and paused both keep their place.
                ADD CONSTRAINT recurring_invoices_ended_has_no_next_run
                    CHECK ((status = 'ended') = (next_run_on IS NULL));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE recurring_invoices
                DROP CONSTRAINT IF EXISTS recurring_invoices_ended_has_no_next_run,
                ADD CONSTRAINT recurring_invoices_active_has_next_run
                    CHECK ((status = 'active') = (next_run_on IS NOT NULL));
        SQL);
    }
};
