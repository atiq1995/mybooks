<?php

declare(strict_types=1);

use App\Console\Commands\GenerateRecurringInvoicesCommand;
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

/*
 * Generate the invoices that recurring templates are due to produce.
 *
 * At 06:00 rather than overnight, and that is deliberate: these invoices go
 * to customers. Generating them at the start of the working day means that
 * when one fails — an archived customer, a closed period — somebody is at a
 * desk to see it, rather than it sitting unnoticed until the next morning.
 *
 * Before `verify-ledger` would have been wrong for the same reason in
 * reverse: the nightly check should see a settled day's work, not a run that
 * is still in progress.
 *
 * Safe to run twice, so an overlapping or repeated invocation cannot
 * double-bill: the unique index on (template, scheduled date) arbitrates.
 * `withoutOverlapping` is belt and braces on top of that.
 *
 * @see \App\Domain\Sales\Actions\GenerateRecurringInvoices
 */
Schedule::command(GenerateRecurringInvoicesCommand::class, ['--json'])
    ->dailyAt('06:00')
    ->onOneServer()
    ->withoutOverlapping()
    // An invoice that silently did not go out is money not asked for, and
    // nobody finds it by reading logs.
    ->emailOutputOnFailure(config()->string('mail.ledger_alerts_to'));
