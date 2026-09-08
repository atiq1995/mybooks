<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Models\Account;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tax\Enums\TaxAppliesTo;
use App\Domain\Tax\Models\Tax;
use App\Domain\Tax\Models\TaxComponent;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tax rates.
 *
 * Configuration rather than a day-to-day screen, but the sales module cannot
 * work without it — so the page leads with what each rate DOES rather than
 * with a table of numbers.
 *
 * Changing a rate creates a new component version from a date; it never edits
 * the old one. That is what makes "a rate change must not retroactively alter
 * a posted document" true by construction rather than by care.
 *
 * @see ACCOUNTING_RULES.md §5
 */
final class TaxController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::SettingsAccounting->value);

        $today = Carbon::now();

        $taxes = Tax::query()
            ->with('components')
            ->when(! $request->boolean('archived'), fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('applies_to')
            ->orderBy('name')
            ->get();

        return Inertia::render('Settings/Taxes', [
            'taxes' => array_values($taxes
                ->map(fn (Tax $tax): array => [
                    'id' => $tax->id,
                    'name' => $tax->name,
                    'code' => $tax->code,
                    'applies_to' => $tax->applies_to->value,
                    'applies_to_label' => $tax->applies_to->label(),
                    'is_inclusive_default' => $tax->is_inclusive_default,
                    'is_zero_rated' => $tax->is_zero_rated,
                    'is_exempt' => $tax->is_exempt,
                    'is_active' => $tax->is_active,
                    'is_archived' => $tax->archived_at !== null,
                    // The total rate in force today, so the list is scannable
                    // without opening each one.
                    'effective_rate' => $this->effectiveRate($tax, $today),
                    'components' => array_values($tax->components
                        ->map(static fn (TaxComponent $component): array => [
                            'id' => $component->id,
                            'name' => $component->name,
                            'rate' => $component->rate,
                            'percentage' => $component->percentage(),
                            'sequence' => $component->sequence,
                            'is_compound' => $component->is_compound,
                            'output_account_id' => $component->output_account_id,
                            'input_account_id' => $component->input_account_id,
                            'effective_from' => $component->effective_from->toDateString(),
                            'effective_to' => $component->effective_to?->toDateString(),
                            // A superseded version is kept, because the
                            // documents it priced still refer to it.
                            'is_current' => $component->effective_to === null
                                || $component->effective_to->greaterThanOrEqualTo($today),
                        ])
                        ->all()),
                ])
                ->all()),
            'showArchived' => $request->boolean('archived'),
            'options' => [
                'applies_to' => array_map(
                    static fn (TaxAppliesTo $case): array => [
                        'value' => $case->value,
                        'label' => $case->label(),
                    ],
                    TaxAppliesTo::cases(),
                ),
                'liabilityAccounts' => $this->accountOptions('liability'),
                'assetAccounts' => $this->accountOptions('asset'),
            ],
            'today' => $today->toDateString(),
            'can' => [
                'manage' => $request->user()?->can(Permission::SettingsAccounting->value) ?? false,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permission::SettingsAccounting->value);

        $validated = $this->validateTax($request);

        $tax = DB::transaction(function () use ($validated, $request): Tax {
            $tax = new Tax;

            $tax->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $this->tenant->organization()->id,
                'name' => self::text($validated, 'name'),
                'code' => mb_strtoupper(self::text($validated, 'code')),
                'applies_to' => self::text($validated, 'applies_to'),
                'is_inclusive_default' => (bool) ($validated['is_inclusive_default'] ?? false),
                'is_zero_rated' => (bool) ($validated['is_zero_rated'] ?? false),
                'is_exempt' => (bool) ($validated['is_exempt'] ?? false),
                'is_active' => true,
            ])->save();

            $this->replaceComponents($tax, self::componentsFrom($validated));

            $this->audit->record(
                action: 'tax.created',
                subject: $tax,
                description: "Created tax {$tax->code} ({$tax->name})",
                new: [
                    'code' => $tax->code,
                    'name' => $tax->name,
                    'applies_to' => $tax->applies_to->value,
                    'components' => count(self::componentsFrom($validated)),
                ],
                actor: $request->user(),
            );

            return $tax;
        });

        return back()->with('success', "{$tax->name} added.");
    }

    /**
     * Edit a tax.
     *
     * Its NAME and flags change in place; its RATE does not. A new rate
     * arrives as a component version effective from a date, and the previous
     * version is closed the day before — so a document keeps the rate that
     * was in force when it was issued.
     */
    public function update(Request $request, Tax $tax): RedirectResponse
    {
        $this->authorize(Permission::SettingsAccounting->value);

        $this->guardBelongsToActiveOrganization($tax);

        $validated = $this->validateTax($request, $tax);

        DB::transaction(function () use ($tax, $validated, $request): void {
            $tax->forceFill([
                'name' => self::text($validated, 'name'),
                'code' => mb_strtoupper(self::text($validated, 'code')),
                'applies_to' => self::text($validated, 'applies_to'),
                'is_inclusive_default' => (bool) ($validated['is_inclusive_default'] ?? false),
                'is_zero_rated' => (bool) ($validated['is_zero_rated'] ?? false),
                'is_exempt' => (bool) ($validated['is_exempt'] ?? false),
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ])->save();

            if (isset($validated['components'])) {
                $this->replaceComponents($tax, self::componentsFrom($validated));
            }

            $this->audit->recordChange(
                action: 'tax.updated',
                subject: $tax,
                description: "Updated tax {$tax->code}",
                actor: $request->user(),
            );
        });

        return back()->with('success', "{$tax->name} updated.");
    }

    public function archive(Request $request, Tax $tax): RedirectResponse
    {
        $this->authorize(Permission::SettingsAccounting->value);

        $this->guardBelongsToActiveOrganization($tax);

        /*
         * Archive, never delete. Every document line that used this tax
         * references its components for the return, and removing them would
         * make a filed period unreproducible.
         */
        $tax->forceFill(['archived_at' => now(), 'is_active' => false])->save();

        $this->audit->record(
            action: 'tax.archived',
            subject: $tax,
            description: "Archived tax {$tax->code}",
            actor: $request->user(),
        );

        return back()->with('success', "{$tax->name} archived. Past documents keep their rates.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTax(Request $request, ?Tax $existing = null): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9._\-]+$/',
                function (string $attribute, mixed $value, callable $fail) use ($existing): void {
                    $clash = Tax::query()
                        ->where('code', mb_strtoupper(is_string($value) ? $value : ''))
                        ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing?->id))
                        ->first();

                    if ($clash !== null) {
                        $fail("{$clash->name} already uses this code.");
                    }
                },
            ],
            'applies_to' => ['required', Rule::in(TaxAppliesTo::values())],
            'is_inclusive_default' => ['nullable', 'boolean'],
            'is_zero_rated' => ['nullable', 'boolean'],
            'is_exempt' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],

            // An exempt tax has none: it is outside the tax entirely.
            'components' => ['nullable', 'array', 'max:10'],
            'components.*.name' => ['required', 'string', 'max:80'],
            /*
             * Entered as a PERCENTAGE, because that is how a rate is written
             * down and quoted. Stored as a fraction, so nothing downstream
             * has to remember to divide.
             */
            'components.*.percentage' => ['required', 'string', 'decimal:0,4'],
            'components.*.sequence' => ['nullable', 'integer', 'min:1', 'max:10'],
            'components.*.is_compound' => ['nullable', 'boolean'],
            'components.*.output_account_id' => ['nullable', 'uuid'],
            'components.*.input_account_id' => ['nullable', 'uuid'],
            'components.*.effective_from' => ['required', 'date'],
        ], [
            'code.regex' => 'Use letters, digits, dots, dashes or underscores — no spaces.',
            'components.*.percentage.decimal' => 'Enter a percentage, such as 18 or 13.5.',
        ]);

        return $validated;
    }

    /**
     * The component rows from validated input.
     *
     * @param  array<string, mixed>  $validated
     * @return list<array<string, mixed>>
     */
    private static function componentsFrom(array $validated): array
    {
        $components = $validated['components'] ?? [];

        if (! is_array($components)) {
            return [];
        }

        $rows = [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            /** @var array<string, mixed> $component */
            $rows[] = $component;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function text(array $input, string $key, string $default = ''): string
    {
        $value = $input[$key] ?? null;

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : $default);
    }

    /**
     * Replace a tax's component versions.
     *
     * Wholesale, because the form edits them as a set. Superseded versions
     * are NOT deleted where a document already references them — the unique
     * index on (tax, sequence, effective_from) means re-submitting the same
     * version updates it rather than duplicating it.
     *
     * @param  list<array<string, mixed>>  $components
     */
    private function replaceComponents(Tax $tax, array $components): void
    {
        $keep = [];

        foreach ($components as $index => $input) {
            $sequence = is_numeric($input['sequence'] ?? null)
                ? (int) $input['sequence']
                : $index + 1;

            $from = Carbon::parse(self::text($input, 'effective_from'))->toDateString();

            // A percentage in, a fraction out: 18 becomes 0.180000.
            $rate = (string) BigDecimal::of(self::text($input, 'percentage', '0'))
                ->dividedBy(100, 6, RoundingMode::HalfUp);

            $component = TaxComponent::query()
                ->where('tax_id', $tax->id)
                ->where('sequence', $sequence)
                ->whereDate('effective_from', $from)
                ->first() ?? new TaxComponent;

            $component->forceFill([
                'id' => $component->id ?? (string) Str::uuid7(),
                'organization_id' => $tax->organization_id,
                'tax_id' => $tax->id,
                'name' => self::text($input, 'name'),
                'rate' => $rate,
                'sequence' => $sequence,
                'is_compound' => (bool) ($input['is_compound'] ?? false),
                'output_account_id' => $this->accountId($input, 'output_account_id'),
                'input_account_id' => $this->accountId($input, 'input_account_id'),
                'effective_from' => $from,
            ])->save();

            $keep[] = $component->id;
        }

        /*
         * Anything the form dropped is removed only if nothing references it.
         * A component a document priced with has to survive, or that
         * document's tax return line becomes unreproducible.
         */
        TaxComponent::query()
            ->where('tax_id', $tax->id)
            ->whereKeyNot($keep)
            ->whereDoesntHave('lineTaxes')
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function accountId(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        // Through the model, so the organisation scope applies.
        return Account::query()->postable()->find($value)?->id;
    }

    /**
     * The combined rate in force on a date, as a percentage for display.
     *
     * Compounding is applied, so "18% + 3% compound" reads as 21.54 rather
     * than 21 — which is the figure a customer's invoice will actually show.
     */
    private function effectiveRate(Tax $tax, Carbon $on): string
    {
        $components = $tax->componentsOn($on);

        if ($components === []) {
            return '0';
        }

        $one = BigDecimal::one();
        $multiplier = $one;

        foreach ($components as $component) {
            $multiplier = $component->is_compound
                ? $multiplier->multipliedBy($one->plus($component->rateValue()))
                : $multiplier->plus($component->rateValue());
        }

        $percentage = (string) $multiplier
            ->minus($one)
            ->multipliedBy(100)
            ->toScale(4, RoundingMode::HalfUp);

        return rtrim(rtrim($percentage, '0'), '.') ?: '0';
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function accountOptions(string $type): array
    {
        return array_values(
            Account::query()
                ->postable()
                ->where('type', $type)
                ->orderBy('code')
                ->get()
                ->map(static fn (Account $account): array => [
                    'value' => $account->id,
                    'label' => "{$account->code} — {$account->name}",
                ])
                ->all(),
        );
    }

    private function guardBelongsToActiveOrganization(Tax $tax): void
    {
        abort_unless($tax->organization_id === $this->tenant->organization()->id, 404);
    }
}
