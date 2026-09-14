<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Models\JournalEntry;
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
 * The organisation's own details.
 *
 * The Phase 1 leftover: creation existed, editing did not, so a typo in a
 * company's legal name was permanent.
 *
 * The screen's real work is saying which decisions can still be changed and
 * which cannot. Two of them are irreversible, and both are irreversible for
 * reasons somebody has to be told rather than discovered:
 *
 *   BASE CURRENCY — every posted amount is stored converted to it. Changing
 *   it would not restate them, it would reinterpret them, so a year of
 *   figures would silently mean something else.
 *
 *   FISCAL YEAR START — once anything is posted, the periods exist and the
 *   comparatives are drawn against them. Moving the year boundary would leave
 *   entries in periods that no longer align with it.
 *
 * So both are shown, both are locked, and each says why. A disabled field
 * with an explanation is better than a field that accepts a change and then
 * refuses it, and far better than one that accepts it.
 */
final class OrganizationSettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit,
    ) {}

    public function show(Request $request): Response
    {
        $this->authorize(Permission::SettingsView->value);

        $organization = $this->tenant->organization();

        /*
         * Whether anything has posted decides what is still changeable.
         *
         * Checked rather than assumed from the onboarding flag: an
         * organisation can finish onboarding and post nothing for a month,
         * and during that month the fiscal year is still safely movable.
         */
        $hasPosted = JournalEntry::query()->exists();

        return Inertia::render('Settings/Organization', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'legal_name' => $organization->legal_name,
                'base_currency' => $organization->base_currency,
                'country_code' => $organization->country_code,
                'jurisdiction' => $organization->jurisdiction,
                'fiscal_year_start_month' => $organization->fiscal_year_start_month,
                'rounding_mode' => $organization->rounding_mode,
                'tax_registration_number' => $organization->tax_registration_number,
                'sales_tax_registration_number' => $organization->sales_tax_registration_number,
                'business_registration_number' => $organization->business_registration_number,
                'timezone' => $organization->timezone,
                'locale' => $organization->locale,
                'date_format' => $organization->date_format,
                'address' => $organization->address,
                'phone' => $organization->phone,
                'email' => $organization->email,
                'website' => $organization->website,
            ],
            /*
             * What is locked, and why — as data, so the page states the
             * reason next to the field rather than in a paragraph nobody
             * reads.
             */
            'locked' => [
                'base_currency' => [
                    'locked' => true,
                    'reason' => 'Every posted amount is stored converted to this currency. '.
                        'Changing it would not restate those figures, it would reinterpret '.
                        'them — so a year of accounts would quietly mean something else.',
                ],
                'fiscal_year_start_month' => [
                    'locked' => $hasPosted,
                    'reason' => $hasPosted
                        ? 'Something has been posted, so the periods exist and the '.
                            'comparatives are drawn against them. Moving the year boundary '.
                            'now would leave entries in periods that no longer line up '.
                            'with it.'
                        : 'Nothing has been posted yet, so this can still be changed. It '.
                            'locks as soon as the first entry lands.',
                ],
            ],
            // Pakistan-first, but the jurisdictions the tax defaults know
            // about are what this list has to reflect.
            'countries' => $this->countries(),
            'timezones' => $this->timezones(),
            'can' => [
                'update' => $request->user()?->can(Permission::SettingsOrganization->value) ?? false,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize(Permission::SettingsOrganization->value);

        $organization = $this->tenant->organization();
        $hasPosted = JournalEntry::query()->exists();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:200'],

            'country_code' => ['required', 'string', 'size:2', 'uppercase'],

            /*
             * Accepted only while nothing has posted, and refused with a
             * reason rather than ignored. Silently dropping a field somebody
             * filled in is the behaviour that makes people distrust a form.
             */
            'fiscal_year_start_month' => [
                'nullable',
                'integer',
                'between:1,12',
                Rule::prohibitedIf($hasPosted),
            ],

            'rounding_mode' => ['required', 'in:HALF_UP,HALF_EVEN,HALF_DOWN'],

            'tax_registration_number' => ['nullable', 'string', 'max:40'],
            'sales_tax_registration_number' => ['nullable', 'string', 'max:40'],
            'business_registration_number' => ['nullable', 'string', 'max:40'],

            'timezone' => ['required', 'string', 'timezone'],
            'locale' => ['required', 'string', 'max:10'],
            'date_format' => ['required', 'string', 'max:20'],

            'address' => ['nullable', 'array'],
            'address.line1' => ['nullable', 'string', 'max:160'],
            'address.line2' => ['nullable', 'string', 'max:160'],
            'address.city' => ['nullable', 'string', 'max:80'],
            'address.state' => ['nullable', 'string', 'max:80'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
            'address.country' => ['nullable', 'string', 'max:80'],

            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'website' => ['nullable', 'url', 'max:200'],
        ], [
            'fiscal_year_start_month.prohibited' => 'The financial year cannot be moved once '.
                'something has been posted: the periods exist and the comparatives are drawn '.
                'against them.',
        ]);

        /*
         * The base currency is not in the rules at all.
         *
         * Not "validated and rejected" — absent, so there is no path through
         * this controller that could change it however the request is
         * shaped. The one decision in the product that cannot be undone gets
         * the strongest guard available, which is not being writable.
         */
        $before = [
            'name' => $organization->name,
            'legal_name' => $organization->legal_name,
            'timezone' => $organization->timezone,
            'rounding_mode' => $organization->rounding_mode,
        ];

        /** @var array<string, mixed> $attributes */
        $attributes = is_array($validated) ? $validated : [];

        // Absent rather than null: a nullable field left out of the form
        // should keep its value, and `fiscal_year_start_month` is never
        // nullable on the row.
        if (! array_key_exists('fiscal_year_start_month', $attributes)
            || $attributes['fiscal_year_start_month'] === null
            || $hasPosted) {
            unset($attributes['fiscal_year_start_month']);
        }

        DB::transaction(function () use ($organization, $attributes, $request, $before): void {
            $organization->fill($attributes)->save();

            $this->audit->record(
                action: 'organizations.updated',
                subject: $organization,
                description: "Updated organisation details for {$organization->name}",
                old: $before,
                new: [
                    'name' => $organization->name,
                    'legal_name' => $organization->legal_name,
                    'timezone' => $organization->timezone,
                    'rounding_mode' => $organization->rounding_mode,
                ],
                actor: $request->user(),
            );
        });

        return back()->with('success', 'Organisation details updated.');
    }

    /**
     * The jurisdictions the tax defaults know about, plus the common ones.
     *
     * A short list rather than every ISO country: each entry implies a set of
     * tax defaults and a fiscal-year convention, and offering a country the
     * product has no defaults for would promise something it cannot deliver.
     *
     * @return list<array{value: string, label: string, fiscal_year_start: int}>
     */
    private function countries(): array
    {
        return [
            ['value' => 'PK', 'label' => 'Pakistan', 'fiscal_year_start' => 7],
            ['value' => 'GB', 'label' => 'United Kingdom', 'fiscal_year_start' => 4],
            ['value' => 'AE', 'label' => 'United Arab Emirates', 'fiscal_year_start' => 1],
            ['value' => 'US', 'label' => 'United States', 'fiscal_year_start' => 1],
            ['value' => 'IN', 'label' => 'India', 'fiscal_year_start' => 4],
            ['value' => 'SA', 'label' => 'Saudi Arabia', 'fiscal_year_start' => 1],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function timezones(): array
    {
        return array_values(array_map(
            static fn (string $timezone): array => [
                'value' => $timezone,
                'label' => str_replace('_', ' ', $timezone),
            ],
            [
                'Asia/Karachi',
                'Asia/Dubai',
                'Asia/Kolkata',
                'Asia/Riyadh',
                'Europe/London',
                'America/New_York',
                'America/Chicago',
                'America/Los_Angeles',
                'UTC',
            ],
        ));
    }
}
