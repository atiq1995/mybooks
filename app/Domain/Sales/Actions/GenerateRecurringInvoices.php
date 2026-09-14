<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Domain\Sales\Models\RecurringInvoice;
use App\Domain\Sales\Models\RecurringInvoiceLine;
use App\Domain\Sales\Models\RecurringInvoiceRun;
use App\Domain\Sales\Models\SalesDocument;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turn due templates into invoices.
 *
 * Four decisions here, and each of them is the difference between a scheduler
 * that can be trusted with billing and one that cannot:
 *
 * IT CATCHES UP. A template due on the 1st that is not run until the 5th —
 * because the server was down, the worker was stuck, or nobody set the
 * scheduler up until Friday — generates the occurrence for the 1st, dated the
 * 1st. And if three occurrences were missed, it generates three. Skipping to
 * "now" would silently drop invoices a business is owed, which is the worst
 * failure this module has available to it.
 *
 * EACH INVOICE IS DATED ON ITS OWN OCCURRENCE, not on the run. That is what
 * §6 means by "at its own date": the tax rates that apply are the ones in
 * force then, the due date runs from then, and the ageing is right.
 *
 * A RUN ROW IS WRITTEN BEFORE THE INVOICE, in the same transaction, and the
 * unique index on (template, scheduled date) is what makes double-billing
 * impossible. A scheduler firing twice, a retried worker, and somebody
 * pressing "generate now" mid-run are three different races; only the
 * database can arbitrate between processes.
 *
 * A FAILURE ENDS THAT TEMPLATE'S RUN, and is recorded. If the customer has
 * been archived or the period is closed, every later occurrence would fail
 * the same way — so the schedule stops where it stopped, keeping its place,
 * and somebody is told once instead of a thousand times.
 *
 * @see ACCOUNTING_RULES.md §6, §9
 */
final readonly class GenerateRecurringInvoices
{
    /**
     * How many occurrences one template may generate in a single run.
     *
     * A guard against a template whose start date was typed as 2015: without
     * it, one save would produce a hundred and thirty invoices and email
     * them. Hitting the cap leaves `next_run_on` where it is, so the rest
     * follow tomorrow — the work is delayed, never lost.
     */
    public const int MAX_CATCH_UP_PER_RUN = 24;

    public function __construct(
        private SaveSalesDocument $saveSalesDocument,
        private IssueSalesDocument $issueSalesDocument,
        private AuditRecorder $audit,
    ) {}

    /**
     * Every template due on or before a date.
     *
     * @return array{generated: int, failed: int, templates: int}
     */
    public function handle(?Carbon $on = null, ?User $actor = null): array
    {
        $on ??= Carbon::now();

        $templates = RecurringInvoice::query()->due($on)->with('lines')->get();

        $generated = 0;
        $failed = 0;

        foreach ($templates as $template) {
            $result = $this->runTemplate($template, $on, $actor);

            $generated += $result['generated'];
            $failed += $result['failed'];
        }

        return [
            'generated' => $generated,
            'failed' => $failed,
            'templates' => $templates->count(),
        ];
    }

    /**
     * One template, catching up on everything it owes.
     *
     * @return array{generated: int, failed: int}
     */
    public function runTemplate(
        RecurringInvoice $template,
        ?Carbon $on = null,
        ?User $actor = null,
    ): array {
        $on ??= Carbon::now();

        $generated = 0;
        $failed = 0;

        for ($occurrence = 0; $occurrence < self::MAX_CATCH_UP_PER_RUN; $occurrence++) {
            $template->refresh();

            $due = $template->next_run_on;

            if (! $template->isActive() || $due === null || $due->greaterThan($on)) {
                break;
            }

            /*
             * The agreement's own end, checked before generating rather than
             * after. A template that has reached twelve invoices should not
             * produce a thirteenth and then notice.
             */
            if ($template->hasReachedItsEnd($due)) {
                $this->end($template);

                break;
            }

            try {
                $this->generateOne($template, $due, $actor);
                $generated++;
            } catch (\Throwable $exception) {
                $this->recordFailure($template, $due, $exception);
                $failed++;

                /*
                 * Stop this template here.
                 *
                 * Whatever failed — an archived customer, a closed period, a
                 * chart with no revenue account — would fail identically for
                 * every later occurrence. The schedule keeps its place, so
                 * fixing the cause and running again picks up exactly where
                 * it left off.
                 */
                break;
            }
        }

        return ['generated' => $generated, 'failed' => $failed];
    }

    /**
     * One occurrence: the run row, the invoice, and the schedule moving on.
     */
    private function generateOne(
        RecurringInvoice $template,
        Carbon $due,
        ?User $actor,
    ): SalesDocument {
        return DB::transaction(function () use ($template, $due, $actor): SalesDocument {
            /*
             * The invoice first, then the run row that claims the occurrence.
             *
             * The obvious order is the other way round — claim the slot, then
             * do the work — but a run row asserts that an occurrence was
             * generated, and a check constraint holds it to that: `generated`
             * requires a document. A placeholder row would mean weakening the
             * constraint to allow a state that should never be committed.
             *
             * Doing the work first costs nothing that matters. Two processes
             * racing the same occurrence both build an invoice, then both try
             * to insert the run row; one wins and the other violates the
             * unique index, rolling its whole transaction back — invoice
             * included. The number it claimed goes back too, because
             * DocumentNumberGenerator takes the sequence under `FOR UPDATE`
             * rather than using a PostgreSQL sequence, which is exactly the
             * case it was built for.
             *
             * So the loser does more work before failing, and nothing is
             * double-billed or left with a gap in its numbering.
             */
            $invoice = $this->saveSalesDocument->handle(
                type: SalesDocumentType::Invoice,
                attributes: [
                    'contact_id' => $template->contact_id,
                    // ITS OWN DATE, not the run's.
                    'issue_date' => $due->toDateString(),
                    'due_date' => $due->copy()
                        ->addDays($template->payment_terms_days)
                        ->toDateString(),
                    'reference' => $template->reference,
                    'notes' => $template->notes,
                    'terms' => $template->terms,
                    'currency' => $template->currency,
                    'exchange_rate' => $template->exchange_rate,
                    'prices_include_tax' => $template->prices_include_tax,
                    'discount_type' => $template->discount_type,
                    'discount_value' => $template->discount_value,
                ],
                lines: $this->linesFrom($template),
                actor: $actor,
            );

            // Claims the occurrence. A concurrent attempt collides here.
            $run = new RecurringInvoiceRun;

            $run->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $template->organization_id,
                'recurring_invoice_id' => $template->id,
                'scheduled_for' => $due->toDateString(),
                'ran_at' => Carbon::now(),
                'outcome' => 'generated',
                'sales_document_id' => $invoice->getKey(),
            ])->save();

            /*
             * Issued, or left as a draft.
             *
             * Setting up the template IS the authorisation, given once, in
             * advance, by somebody who could see what would be billed. A
             * business that would rather check each one turns `auto_issue`
             * off and gets drafts.
             */
            if ($template->auto_issue) {
                $invoice = $this->issueSalesDocument->handle(
                    document: $invoice,
                    actor: $actor,
                );
            }

            $this->advance($template, $due);

            $this->audit->record(
                action: 'sales.recurring_invoice_generated',
                subject: $invoice,
                description: sprintf(
                    '%s generated from "%s" for %s%s',
                    $invoice->number,
                    $template->name,
                    $due->toDateString(),
                    $template->auto_issue ? ' and issued' : ' as a draft',
                ),
                new: [
                    'template' => $template->name,
                    'scheduled_for' => $due->toDateString(),
                    'number' => $invoice->number,
                    'total' => $invoice->total,
                    'issued' => $template->auto_issue,
                ],
                actor: $actor,
            );

            return $invoice;
        });
    }

    /**
     * Move the schedule on by one occurrence.
     *
     * Counted from the START DATE, never from today and never from the
     * occurrence just billed. From "now" would make a late run shift every
     * future occurrence with it — a monthly invoice run three days late would
     * bill on the 4th for ever after. From the previous occurrence looks
     * right and drifts at month-end: 31 January clamps to 28 February, and
     * stepping on from there gives 28 March instead of the 31st.
     */
    private function advance(RecurringInvoice $template, Carbon $due): void
    {
        $occurrences = $template->occurrences_generated + 1;

        $next = $template->frequency->occurrence(
            $template->starts_on,
            $occurrences,
            $template->interval,
        );

        $finished = ($template->ends_on !== null && $next->greaterThan($template->ends_on))
            || ($template->max_occurrences !== null && $occurrences >= $template->max_occurrences);

        $template->forceFill([
            'occurrences_generated' => $occurrences,
            'last_run_on' => $due->toDateString(),
            // A constraint ties the status to this field: an active schedule
            // knows when it next runs, an ended one does not.
            'next_run_on' => $finished ? null : $next->toDateString(),
            'status' => $finished ? 'ended' : $template->status,
        ])->save();
    }

    private function end(RecurringInvoice $template): void
    {
        $template->forceFill(['status' => 'ended', 'next_run_on' => null])->save();
    }

    /**
     * Record an occurrence that could not be produced.
     *
     * Written in its own transaction, because the one that failed has rolled
     * back — and a failure nobody can see is worse than the failure.
     */
    private function recordFailure(
        RecurringInvoice $template,
        Carbon $due,
        \Throwable $exception,
    ): void {
        try {
            DB::transaction(function () use ($template, $due, $exception): void {
                $run = new RecurringInvoiceRun;

                $run->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $template->organization_id,
                    'recurring_invoice_id' => $template->id,
                    'scheduled_for' => $due->toDateString(),
                    'ran_at' => Carbon::now(),
                    'outcome' => 'failed',
                    'failure_reason' => mb_substr($exception->getMessage(), 0, 255),
                ])->save();
            });
        } catch (QueryException) {
            /*
             * A run row for this occurrence already exists, which means
             * another process got there first. Nothing to record and nothing
             * wrong — the unique index did its job.
             */
        }
    }

    /**
     * The template's lines, as the save action wants them.
     *
     * Figures are NOT copied: a template has none. The quantity, price and
     * tax come across, and every computed amount is produced by the tax
     * engine on the invoice, at the invoice's own date.
     *
     * @return list<array<string, mixed>>
     */
    private function linesFrom(RecurringInvoice $template): array
    {
        return array_values(
            $template->lines()->get()
                ->map(static fn (RecurringInvoiceLine $line): array => [
                    'item_id' => $line->item_id,
                    'description' => $line->description,
                    'unit' => $line->unit,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_type' => $line->discount_type,
                    'discount_value' => $line->discount_value,
                    'tax_id' => $line->tax_id,
                    'revenue_account_id' => $line->revenue_account_id,
                ])
                ->all(),
        );
    }
}
