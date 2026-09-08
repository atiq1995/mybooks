<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Actions\CreateFiscalYear;
use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Financial years and the periods inside them.
 *
 * Three states, and the difference between the last two is the whole point of
 * the screen: an OPEN period accepts postings; a CLOSED one accepts them only
 * from somebody holding `accounting.post_to_closed_period`, audited every
 * time; a LOCKED one accepts none, ever, and cannot be reopened. Locking is
 * what "we have filed this" means, so the confirmation says so.
 *
 * @see ACCOUNTING_RULES.md §7
 */
final class FiscalPeriodController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit,
        private readonly CreateFiscalYear $createFiscalYear,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::AccountingView->value);

        $organization = $this->tenant->organization();

        $years = FiscalYear::query()
            ->with('periods')
            ->orderByDesc('starts_on')
            ->get();

        $entryCounts = $this->entryCountsByPeriod();

        return Inertia::render('Accounting/Periods', [
            'years' => $years->map(fn (FiscalYear $year): array => [
                'id' => $year->id,
                'label' => $year->label,
                'starts_on' => $year->starts_on->toDateString(),
                'ends_on' => $year->ends_on->toDateString(),
                'status' => $year->status->value,
                'status_label' => $year->status->label(),
                'periods' => $year->periods->map(fn (FiscalPeriod $period): array => [
                    'id' => $period->id,
                    'sequence' => $period->sequence,
                    'label' => $period->label,
                    'starts_on' => $period->starts_on->toDateString(),
                    'ends_on' => $period->ends_on->toDateString(),
                    'status' => $period->status->value,
                    'status_label' => $period->status->label(),
                    'can_reopen' => $period->status->canReopen(),
                    // Shown on the confirmation, so closing a period with
                    // entries in it is a considered act rather than a reflex.
                    'entries' => $entryCounts[$period->id] ?? 0,
                ])->values()->all(),
            ])->values()->all(),
            'baseCurrency' => $organization->base_currency,
            'fiscalYearStartMonth' => $organization->fiscal_year_start_month,
            'can' => [
                'manage' => $request->user()?->can(Permission::AccountingManagePeriods->value) ?? false,
                'post_to_closed' => $request->user()
                    ?->can(Permission::AccountingPostToClosedPeriod->value) ?? false,
            ],
        ]);
    }

    /**
     * Change a period's state.
     *
     * One endpoint rather than three, because the guard that matters is the
     * same in every direction: a locked period never changes again.
     */
    public function updateStatus(Request $request, FiscalPeriod $period): RedirectResponse
    {
        $this->authorize(Permission::AccountingManagePeriods->value);

        $this->guardBelongsToActiveOrganization($period);

        $request->validate([
            'status' => ['required', Rule::in(['open', 'closed', 'locked'])],
        ]);

        $target = PeriodStatus::from((string) $request->string('status'));

        if ($period->status === PeriodStatus::Locked) {
            return back()->with(
                'error',
                "{$period->label} is locked. Locked periods are never reopened — the figures ".
                'have been filed. Post a correcting entry in an open period instead.',
            );
        }

        if ($period->status === $target) {
            return back();
        }

        $previous = $period->status;

        DB::transaction(function () use ($period, $target, $previous, $request): void {
            $period->forceFill([
                'status' => $target,
                'closed_at' => $target === PeriodStatus::Open ? null : now(),
                'closed_by' => $target === PeriodStatus::Open ? null : $request->user()?->getKey(),
            ])->save();

            $this->audit->record(
                action: 'accounting.period_'.$target->value,
                subject: $period,
                description: sprintf(
                    '%s %s (was %s)',
                    match ($target) {
                        PeriodStatus::Open => 'Reopened',
                        PeriodStatus::Closed => 'Closed',
                        PeriodStatus::Locked => 'LOCKED',
                    },
                    $period->label,
                    $previous->label(),
                ),
                old: ['status' => $previous->value],
                new: ['status' => $target->value],
                actor: $request->user(),
            );
        });

        return back()->with('success', "{$period->label} is now {$target->label()}.");
    }

    /**
     * Open the next financial year.
     *
     * Offered explicitly rather than created on demand: an organisation
     * should know when its books gained a year, and a year created silently
     * by the first back-dated posting is a year nobody reviewed.
     */
    public function storeYear(Request $request): RedirectResponse
    {
        $this->authorize(Permission::AccountingManagePeriods->value);

        $request->validate([
            'starting_year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $organization = $this->tenant->organization();
        $startingYear = $request->integer('starting_year');

        // The day the proposed year would open. If any existing year already
        // covers it, the two would overlap and a posting date would belong to
        // two periods — which the exclusion constraint refuses anyway, less
        // legibly.
        $opensOn = now()->setDate($startingYear, $organization->fiscal_year_start_month, 1);

        $existing = FiscalYear::query()
            ->whereDate('starts_on', '<=', $opensOn)
            ->whereDate('ends_on', '>=', $opensOn)
            ->exists();

        if ($existing) {
            return back()->withErrors([
                'starting_year' => 'A financial year already covers that date. '.
                    'Periods must not overlap, or a posting date would belong to two of them.',
            ]);
        }

        $year = $this->createFiscalYear->handle($organization, $startingYear, $request->user());

        return back()->with('success', "Financial year {$year->label} opened.");
    }

    /**
     * How many entries sit in each period, in one query.
     *
     * @return array<string, int>
     */
    private function entryCountsByPeriod(): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('journal_entries')
            ->groupBy('fiscal_period_id')
            ->selectRaw('fiscal_period_id, COUNT(*) AS total')
            ->pluck('total', 'fiscal_period_id')
            ->map(static fn (mixed $total): int => is_numeric($total) ? (int) $total : 0)
            ->all();

        return $counts;
    }

    private function guardBelongsToActiveOrganization(FiscalPeriod $period): void
    {
        abort_unless(
            $period->organization_id === $this->tenant->organization()->id,
            404,
        );
    }
}
