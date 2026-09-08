<?php

declare(strict_types=1);

use App\Console\Commands\VerifyLedgerCommand;
use Illuminate\Support\Facades\Schedule;

/*
|---------------------------------------------------------------------------
| Scheduled work
|---------------------------------------------------------------------------
|
| Run by the `scheduler` service in docker-compose, which does nothing but
| `schedule:work`. Nothing here may assume a tenant context: a scheduled
| command runs outside any request, so anything organisation-scoped
| establishes its own context explicitly.
|
| @see docker-compose.yml
*/

/*
 * Re-derive every ledger invariant from raw journal lines, nightly.
 *
 * The third of the three independent balance checks — the domain refuses an
 * unbalanced draft, a deferred constraint trigger refuses it at COMMIT, and
 * this catches anything that got past both: a migration that dropped a
 * trigger, a direct SQL fix applied at 2am, a partially restored backup.
 *
 * At 02:15, after the day's work and before anybody reads a report on it.
 * Runs on one server only — with several app containers the check would
 * otherwise run once per container, and a discrepancy would be reported as
 * many times as there are replicas.
 *
 * `--json` because whatever collects this output is a machine.
 *
 * @see ACCOUNTING_RULES.md §1, §10
 */
Schedule::command(VerifyLedgerCommand::class, ['--json'])
    ->dailyAt('02:15')
    ->onOneServer()
    ->withoutOverlapping()
    // A failure here means the books may be misstated, which is the loudest
    // thing this system has to say. It must never be swallowed.
    ->emailOutputOnFailure(config()->string('mail.ledger_alerts_to'));
