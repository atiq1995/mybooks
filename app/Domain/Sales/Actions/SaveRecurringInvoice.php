<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Accounting\Models\Account;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Catalog\Models\Item;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Sales\Enums\RecurrenceFrequency;
use App\Domain\Sales\Exceptions\SalesDocumentRefused;
use App\Domain\Sales\Models\RecurringInvoice;
use App\Domain\Sales\Models\RecurringInvoiceLine;
use App\Domain\Sales\Models\RecurringInvoiceRun;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create or replace a recurring-invoice template.
 *
 * A template is editable for its whole life — unlike an invoice, which
 * becomes a record the moment it is issued. Nothing about a template has
 * posted: the invoices it has already produced are the records, and they are
 * untouched by a change here. That is the point of keeping them apart.
 *
 * `next_run_on` is the working state and is never taken from the caller. It
 * is derived: for a new template, the first occurrence on or after the start
 * date; for an edited one, kept where it is unless the schedule itself moved.
 * Accepting it from a form would let somebody skip or repeat a billing period
 * by typing a date.
 *
 * @see ACCOUNTING_RULES.md §6
 */
final readonly class SaveRecurringInvoice
{
    public function __construct(
        private TenantContext $tenant,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function handle(
        array $attributes,
        array $lines,
        ?RecurringInvoice $template = null,
        ?User $actor = null,
    ): RecurringInvoice {
        $organization = $this->tenant->organization();

        $contact = Contact::query()->findOrFail(self::text($attributes, 'contact_id'));

        if (! $contact->acceptsDocuments()) {
            throw SalesDocumentRefused::contactUnusable($contact->display_name);
        }

        if (! $contact->kind->isCustomer()) {
            throw SalesDocumentRefused::notACustomer($contact->display_name);
        }

        $frequency = RecurrenceFrequency::from(self::text($attributes, 'frequency'));
        $interval = $attributes['interval'] ?? 1;
        $interval = max(1, is_numeric($interval) ? (int) $interval : 1);
        $startsOn = Carbon::parse(self::text($attributes, 'starts_on'));

        $endsOn = self::optionalText($attributes, 'ends_on');
        $endsOn = $endsOn === null ? null : Carbon::parse($endsOn);

        return DB::transaction(function () use (
            $attributes,
            $lines,
            $template,
            $actor,
            $organization,
            $contact,
            $frequency,
            $interval,
            $startsOn,
            $endsOn,
        ): RecurringInvoice {
            $isNew = $template === null;

            if ($isNew) {
                $template = new RecurringInvoice;

                $template->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->getKey(),
                    'status' => 'active',
                    'occurrences_generated' => 0,
                    'created_by' => $actor?->getKey(),
                ]);
            }

            /** @var RecurringInvoice $template */
            $scheduleMoved = $isNew
                || $template->frequency !== $frequency
                || $template->interval !== $interval
                || ! $template->starts_on->isSameDay($startsOn);

            /*
             * Where the schedule next runs.
             *
             * Recomputed only when the schedule itself changed, so editing a
             * line or a note leaves the billing cycle exactly where it was.
             * When it did change, the next run is the first occurrence on or
             * after the start date that has not already been billed.
             */
            $nextRun = $scheduleMoved
                ? $this->firstUnbilledOccurrence($template, $frequency, $interval, $startsOn)
                : $template->next_run_on;

            $ended = $nextRun === null
                || ($endsOn !== null && $nextRun->greaterThan($endsOn));

            $template->forceFill([
                'name' => self::optionalText($attributes, 'name')
                    ?? "{$contact->display_name} — {$frequency->label()}",
                'contact_id' => $contact->getKey(),
                'frequency' => $frequency,
                'interval' => $interval,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn?->toDateString(),
                'max_occurrences' => isset($attributes['max_occurrences'])
                    && is_numeric($attributes['max_occurrences'])
                    ? (int) $attributes['max_occurrences']
                    : null,
                'next_run_on' => $ended ? null : $nextRun?->toDateString(),
                'status' => $ended ? 'ended' : ($template->status ?? 'active'),
                'auto_issue' => (bool) ($attributes['auto_issue'] ?? true),
                'payment_terms_days' => isset($attributes['payment_terms_days'])
                    && is_numeric($attributes['payment_terms_days'])
                    ? (int) $attributes['payment_terms_days']
                    : $contact->payment_terms_days,
                'currency' => self::optionalText($attributes, 'currency')
                    ?? $contact->currency
                    ?? $organization->base_currency,
                'exchange_rate' => self::optionalText($attributes, 'exchange_rate') ?? '1',
                'prices_include_tax' => (bool) ($attributes['prices_include_tax'] ?? false),
                'discount_type' => self::optionalText($attributes, 'discount_type'),
                'discount_value' => self::optionalText($attributes, 'discount_value'),
                'reference' => self::optionalText($attributes, 'reference'),
                'notes' => self::optionalText($attributes, 'notes'),
                'terms' => self::optionalText($attributes, 'terms'),
            ])->save();

            $this->replaceLines($template, $lines);

            $this->audit->record(
                action: $isNew ? 'sales.recurring_invoice_created' : 'sales.recurring_invoice_updated',
                subject: $template,
                description: sprintf(
                    '%s recurring invoice "%s" for %s — %s',
                    $isNew ? 'Created' : 'Updated',
                    $template->name,
                    $contact->display_name,
                    $template->describeSchedule(),
                ),
                new: [
                    'name' => $template->name,
                    'schedule' => $template->describeSchedule(),
                    'next_run_on' => $template->next_run_on?->toDateString(),
                    'auto_issue' => $template->auto_issue,
                    'lines' => count($lines),
                ],
                actor: $actor,
            );

            return $template->refresh();
        });
    }

    /**
     * Change whether a template is running, without touching its schedule.
     *
     * Pausing keeps `next_run_on` so resuming picks up where it left off —
     * and if that date has passed by then, the catch-up in
     * {@see GenerateRecurringInvoices} bills the occurrences that were missed.
     * Which is the right answer: a paused retainer that resumes still owes
     * the months it covered.
     */
    public function setStatus(
        RecurringInvoice $template,
        string $status,
        ?User $actor = null,
    ): RecurringInvoice {
        if (! in_array($status, ['active', 'paused', 'ended'], strict: true)) {
            throw new \InvalidArgumentException("Unknown status {$status}.");
        }

        return DB::transaction(function () use ($template, $status, $actor): RecurringInvoice {
            /*
             * Resuming a template whose next run is in the past leaves it
             * there on purpose. The constraint requires an active template to
             * have one, so a template ended with no date cannot simply be
             * reactivated — it needs a schedule, which means editing it.
             */
            if ($status === 'active' && $template->next_run_on === null) {
                throw new \InvalidArgumentException(
                    'This schedule has run to its end, so there is nothing left to resume. '.
                    'Edit it to give it a new start date, or create a new one.'
                );
            }

            $template->forceFill([
                'status' => $status,
                'next_run_on' => $status === 'ended' ? null : $template->next_run_on,
            ])->save();

            $this->audit->record(
                action: 'sales.recurring_invoice_status_changed',
                subject: $template,
                description: sprintf('Recurring invoice "%s" is now %s', $template->name, $status),
                new: ['status' => $status],
                actor: $actor,
            );

            return $template->refresh();
        });
    }

    /**
     * The first occurrence that has not already been billed.
     *
     * Walking forward from the start date rather than jumping to today, so a
     * schedule created with a start date in the past bills the periods it
     * covers — which is what somebody entering a retainer that began in
     * January means. Occurrences already generated are skipped, so editing
     * the schedule cannot re-bill them.
     */
    private function firstUnbilledOccurrence(
        RecurringInvoice $template,
        RecurrenceFrequency $frequency,
        int $interval,
        Carbon $startsOn,
    ): ?Carbon {
        $billed = $template->exists
            ? $template->runs()->get()
                ->map(static fn (RecurringInvoiceRun $run): string => $run->scheduled_for->toDateString())
                ->all()
            : [];

        /*
         * Each candidate counted from the anchor, not stepped from the last
         * one — the same month-end reason as in the generator: stepping
         * clamps 31 January to 28 February and then keeps the 28th for ever.
         *
         * Bounded, so a start date typed as 1915 cannot spin. Two hundred
         * occurrences is longer than any arrangement this will meet.
         */
        for ($index = 0; $index < 200; $index++) {
            $candidate = $frequency->occurrence($startsOn, $index, $interval);

            if (! in_array($candidate->toDateString(), $billed, strict: true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(RecurringInvoice $template, array $lines): void
    {
        RecurringInvoiceLine::query()
            ->where('recurring_invoice_id', $template->id)
            ->delete();

        $defaultRevenue = $this->defaultRevenueAccountId();
        $lineNo = 1;

        foreach ($lines as $input) {
            $item = isset($input['item_id']) && is_string($input['item_id'])
                ? Item::query()->find($input['item_id'])
                : null;

            $line = new RecurringInvoiceLine;

            $line->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $template->organization_id,
                'recurring_invoice_id' => $template->id,
                'line_no' => $lineNo++,
                'item_id' => $item?->id,
                'description' => self::optionalText($input, 'description')
                    ?? ($item === null ? '' : $item->name),
                'unit' => self::optionalText($input, 'unit') ?? $item?->unit,
                'quantity' => self::optionalText($input, 'quantity') ?? '1',
                'unit_price' => self::optionalText($input, 'unit_price')
                    ?? ($item === null ? null : $item->sale_price)
                    ?? '0',
                'discount_type' => self::optionalText($input, 'discount_type'),
                'discount_value' => self::optionalText($input, 'discount_value'),
                'tax_id' => self::optionalText($input, 'tax_id') ?? $item?->sales_tax_id,
                'revenue_account_id' => self::optionalText($input, 'revenue_account_id')
                    ?? ($item === null ? null : $item->sales_account_id)
                    ?? $defaultRevenue,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function text(array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException("A recurring invoice needs a {$key}.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function optionalText(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    private function defaultRevenueAccountId(): string
    {
        $account = Account::query()
            ->postable()
            ->where('type', 'income')
            ->whereNull('system_role')
            ->orderBy('code')
            ->first();

        return $account->id
            ?? Account::query()->postable()->orderBy('code')->sole()->id;
    }
}
