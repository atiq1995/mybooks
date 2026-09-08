<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Access\Enums\Permission;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Expenses\Models\MileageRate;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mileage rates.
 *
 * A rate change is a NEW rate with a later start date, and the old one is
 * closed the day before — never an edit. The same rule the tax components
 * follow, for the same reason: a claim made in March at last year's rate has
 * to keep reading as that rate for ever, and editing the row would restate
 * it.
 *
 * @see ACCOUNTING_RULES.md §5
 */
final class MileageRateController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::SettingsAccounting->value);

        $organization = $this->tenant->organization();

        $rates = MileageRate::query()
            ->orderBy('unit')
            ->orderByDesc('effective_from')
            ->get();

        return Inertia::render('Settings/MileageRates', [
            'rates' => array_values($rates
                ->map(static fn (MileageRate $rate): array => [
                    'id' => $rate->id,
                    'name' => $rate->name,
                    'unit' => $rate->unit,
                    'rate' => $rate->rate,
                    'effective_from' => $rate->effective_from->toDateString(),
                    'effective_to' => $rate->effective_to?->toDateString(),
                    'is_current' => $rate->effective_to === null,
                    'is_default' => $rate->is_default,
                ])
                ->all()),
            'baseCurrency' => $organization->base_currency,
            'today' => Carbon::now()->toDateString(),
            'can' => [
                'manage' => $request->user()?->can(Permission::SettingsAccounting->value) ?? false,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permission::SettingsAccounting->value);

        $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'unit' => ['required', 'in:km,mi'],
            // A string, because a JSON number is a double and this becomes an
            // amount of money per kilometre.
            'rate' => ['required', 'string', 'decimal:0,4'],
            'effective_from' => ['required', 'date'],
        ]);

        // Read back through the typed accessors rather than the validated
        // array, which is `mixed` to static analysis.
        /*
         * `$rateValue`, not `$rate`.
         *
         * The name matters more than it looks: a MileageRate model is also
         * naturally called `$rate`, and the two colliding in this method
         * assigned the MODEL into the `rate` attribute — which is cast to a
         * string, so casting called __toString(), which serialised the model,
         * which cast the attribute again. The result was not an error but a
         * stack overflow.
         */
        $name = $request->string('name')->toString();
        $unit = $request->string('unit')->toString();
        $rateValue = $request->string('rate')->toString();
        $from = Carbon::parse($request->string('effective_from')->toString());

        DB::transaction(function () use ($name, $rateValue, $unit, $from, $request): void {
            /*
             * Close the outgoing rate the day before this one starts.
             *
             * Two open rates for the same unit would make "the rate on this
             * date" ambiguous, and the resolver would quietly pick one. The
             * partial unique index refuses two open defaults outright; this
             * is what keeps the history readable as a chain.
             */
            $current = MileageRate::query()
                ->where('unit', $unit)
                ->whereNull('effective_to')
                ->get();

            foreach ($current as $outgoing) {
                if ($outgoing->effective_from->greaterThanOrEqualTo($from)) {
                    // A rate starting on or after the new one is not history,
                    // it is a mistake being corrected.
                    $outgoing->delete();

                    continue;
                }

                $outgoing->forceFill([
                    'effective_to' => $from->copy()->subDay()->toDateString(),
                    'is_default' => false,
                ])->save();
            }

            $mileageRate = new MileageRate;

            $mileageRate->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $this->tenant->organization()->getKey(),
                'name' => $name,
                'unit' => $unit,
                'rate' => $rateValue,
                'effective_from' => $from->toDateString(),
                'effective_to' => null,
                'is_default' => true,
            ])->save();

            $this->audit->record(
                action: 'settings.mileage_rate_set',
                subject: $mileageRate,
                description: sprintf(
                    'Mileage rate for %s set to %s from %s',
                    $unit,
                    $mileageRate->rate,
                    $mileageRate->effective_from->toDateString(),
                ),
                new: [
                    'unit' => $unit,
                    'rate' => $mileageRate->rate,
                    'effective_from' => $mileageRate->effective_from->toDateString(),
                ],
                actor: $request->user(),
            );
        });

        return back()->with('success', sprintf(
            'Mileage rate for %s set. Claims dated before %s keep the rate that was in '.
            'force then.',
            $unit,
            $from->toDateString(),
        ));
    }
}
