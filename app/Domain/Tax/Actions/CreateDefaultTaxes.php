<?php

declare(strict_types=1);

namespace App\Domain\Tax\Actions;

use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Tax\Enums\TaxAppliesTo;
use App\Domain\Tax\Models\Tax;
use App\Domain\Tax\Models\TaxComponent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The taxes an organisation starts with.
 *
 * Rates are DATA, seeded per organisation and versioned by effective date —
 * never constants in the posting rules. A rate change is then a new component
 * version, and it cannot retroactively alter a posted document because posted
 * documents keep the amounts they were computed with.
 *
 * Pakistan's set is the default because that is the jurisdiction this product
 * was built for first. The withholding entries look odd next to the others and
 * are meant to: withholding never appears on a document at all, it is a
 * deduction at payment time, and it lives here only so the rate is
 * configurable rather than hard-coded into the payment action.
 *
 * Idempotent, like everything in ledger setup: re-running adds only what is
 * missing, so a resumed wizard cannot produce two GSTs.
 *
 * @see ACCOUNTING_RULES.md §5
 */
final readonly class CreateDefaultTaxes
{
    public function __construct(
        private AuditRecorder $audit,
    ) {}

    /**
     * @return int how many taxes were created
     */
    public function handle(Organization $organization, ?User $actor = null): int
    {
        return DB::transaction(function () use ($organization, $actor): int {
            $accounts = $this->systemAccounts();
            $existing = Tax::query()->pluck('code')->all();

            /*
             * Effective from the start of the current financial year rather
             * than today: an organisation entering a back-dated invoice from
             * earlier in the year must find a rate, and "no rate on that
             * date" would be a refusal nobody could act on during setup.
             */
            $effectiveFrom = $this->fiscalYearStart($organization);

            $created = 0;

            foreach ($this->definitions($organization->jurisdiction) as $definition) {
                if (in_array($definition['code'], $existing, strict: true)) {
                    continue;
                }

                $tax = new Tax;

                $tax->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->getKey(),
                    'name' => $definition['name'],
                    'code' => $definition['code'],
                    'applies_to' => $definition['applies_to'],
                    'is_inclusive_default' => false,
                    'is_zero_rated' => $definition['is_zero_rated'],
                    'is_exempt' => $definition['is_exempt'],
                    'is_active' => true,
                ])->save();

                foreach ($definition['components'] as $sequence => $component) {
                    $taxComponent = new TaxComponent;

                    $taxComponent->forceFill([
                        'id' => (string) Str::uuid7(),
                        'organization_id' => $organization->getKey(),
                        'tax_id' => $tax->getKey(),
                        'name' => $component['name'],
                        'rate' => $component['rate'],
                        'sequence' => $sequence + 1,
                        'is_compound' => $component['is_compound'],
                        'output_account_id' => $accounts[$component['output']] ?? null,
                        'input_account_id' => $accounts[$component['input']] ?? null,
                        'effective_from' => $effectiveFrom->toDateString(),
                    ])->save();
                }

                $created++;
            }

            if ($created > 0) {
                $this->audit->record(
                    action: 'tax.defaults_created',
                    description: "Created {$created} tax rate(s) for {$organization->jurisdiction}",
                    new: [
                        'taxes_created' => $created,
                        'jurisdiction' => $organization->jurisdiction,
                        'effective_from' => $effectiveFrom->toDateString(),
                    ],
                    actor: $actor,
                );
            }

            return $created;
        });
    }

    /**
     * Whether this organisation already has taxes to work with.
     */
    public function isReady(): bool
    {
        return Tax::query()->usable()->forSales()->exists();
    }

    /**
     * @return list<array{
     *     code: string,
     *     name: string,
     *     applies_to: string,
     *     is_zero_rated: bool,
     *     is_exempt: bool,
     *     components: list<array{name: string, rate: string, is_compound: bool, output: string, input: string}>
     * }>
     */
    private function definitions(string $jurisdiction): array
    {
        // Only Pakistan is characterised so far. Everywhere else gets the
        // structural entries — zero-rated and exempt — and configures its own
        // rates, which is better than inheriting somebody else's.
        $common = [
            [
                'code' => 'ZERO',
                'name' => 'Zero-rated (0%)',
                'applies_to' => TaxAppliesTo::Both->value,
                // Taxable at 0% and reportable, which is NOT the same as
                // exempt — the distinction is a filing error if collapsed.
                'is_zero_rated' => true,
                'is_exempt' => false,
                'components' => [
                    [
                        'name' => 'Zero-rated',
                        'rate' => '0.000000',
                        'is_compound' => false,
                        'output' => 'gst_output',
                        'input' => 'gst_input',
                    ],
                ],
            ],
            [
                'code' => 'EXEMPT',
                'name' => 'Exempt',
                'applies_to' => TaxAppliesTo::Both->value,
                'is_zero_rated' => false,
                'is_exempt' => true,
                // No components at all: outside the tax entirely, so nothing
                // is charged and nothing is reported.
                'components' => [],
            ],
        ];

        if ($jurisdiction !== 'PK') {
            return $common;
        }

        return [
            [
                'code' => 'GST18',
                'name' => 'GST 18% (goods)',
                'applies_to' => TaxAppliesTo::Both->value,
                'is_zero_rated' => false,
                'is_exempt' => false,
                'components' => [
                    [
                        'name' => 'GST',
                        'rate' => '0.180000',
                        'is_compound' => false,
                        'output' => 'gst_output',
                        'input' => 'gst_input',
                    ],
                ],
            ],
            [
                'code' => 'PST13',
                'name' => 'Provincial sales tax 13% (services)',
                'applies_to' => TaxAppliesTo::Both->value,
                'is_zero_rated' => false,
                'is_exempt' => false,
                'components' => [
                    [
                        'name' => 'Provincial sales tax',
                        'rate' => '0.130000',
                        'is_compound' => false,
                        'output' => 'gst_output',
                        'input' => 'gst_input',
                    ],
                ],
            ],
            [
                'code' => 'GST18F',
                'name' => 'GST 18% + further tax 3% (unregistered buyer)',
                'applies_to' => TaxAppliesTo::Sales->value,
                'is_zero_rated' => false,
                'is_exempt' => false,
                'components' => [
                    [
                        'name' => 'GST',
                        'rate' => '0.180000',
                        'is_compound' => false,
                        'output' => 'gst_output',
                        'input' => 'gst_input',
                    ],
                    [
                        // Compound: charged on the value INCLUDING the GST
                        // before it. Treating it as simple understates the
                        // liability, and nobody notices until the return.
                        'name' => 'Further tax',
                        'rate' => '0.030000',
                        'is_compound' => true,
                        'output' => 'gst_output',
                        'input' => 'gst_input',
                    ],
                ],
            ],
            ...$common,
            /*
             * Withholding. Never on a document — these exist so the rate at
             * payment time is configurable data rather than a constant in the
             * payment action.
             *
             * A filer's rate is roughly half a non-filer's, which is why
             * `is_tax_filer` on a contact changes arithmetic rather than being
             * a note.
             */
            [
                'code' => 'WHT-S',
                'name' => 'Income tax withholding — services (filer)',
                'applies_to' => TaxAppliesTo::Withholding->value,
                'is_zero_rated' => false,
                'is_exempt' => false,
                'components' => [
                    [
                        'name' => 'Withholding — services',
                        'rate' => '0.100000',
                        'is_compound' => false,
                        'output' => 'wht_payable',
                        'input' => 'wht_receivable',
                    ],
                ],
            ],
            [
                'code' => 'WHT-G',
                'name' => 'Income tax withholding — goods (filer)',
                'applies_to' => TaxAppliesTo::Withholding->value,
                'is_zero_rated' => false,
                'is_exempt' => false,
                'components' => [
                    [
                        'name' => 'Withholding — goods',
                        'rate' => '0.045000',
                        'is_compound' => false,
                        'output' => 'wht_payable',
                        'input' => 'wht_receivable',
                    ],
                ],
            ],
        ];
    }

    /**
     * The control accounts a tax component can point at, by role.
     *
     * `input` on a withholding component is the RECEIVABLE — tax withheld
     * from us, recoverable — and `output` is the PAYABLE, tax we withheld and
     * owe onward. The names read backwards for withholding because the
     * columns mean "what happens on a sale" and "what happens on a purchase",
     * and withholding inverts which side is ours.
     *
     * @return array<string, string>
     */
    private function systemAccounts(): array
    {
        $roles = [
            'gst_output' => SystemAccount::GstOutput,
            'gst_input' => SystemAccount::GstInput,
            'wht_payable' => SystemAccount::WithholdingTaxPayable,
            'wht_receivable' => SystemAccount::WithholdingTaxReceivable,
        ];

        $accounts = [];

        foreach ($roles as $key => $role) {
            $accounts[$key] = Account::query()
                ->where('system_role', $role->value)
                ->value('id');
        }

        return array_filter(
            $accounts,
            static fn (mixed $id): bool => is_string($id),
        );
    }

    private function fiscalYearStart(Organization $organization): Carbon
    {
        $now = Carbon::now();
        $month = $organization->fiscal_year_start_month;

        $year = (int) $now->format('n') >= $month
            ? (int) $now->format('Y')
            : (int) $now->format('Y') - 1;

        return Carbon::create($year, $month, 1) ?? $now->copy()->startOfYear();
    }
}
