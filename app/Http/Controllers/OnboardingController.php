<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Actions\PrepareLedger;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organizations\Support\OrganizationOptions;
use App\Http\Requests\Organizations\UpdateOnboardingDetailsRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The setup wizard a new organisation walks through before it can be used.
 *
 * Until it finishes, the organisation has no chart of accounts and cannot post
 * anything — so the application keeps routing its users back here rather than
 * into an accounting UI that would not work.
 *
 * The wizard collects the business details that appear on documents, invites
 * the team, and builds the ledger: a chart of accounts for the organisation's
 * jurisdiction and an open financial year starting in its own fiscal month.
 *
 * The ledger step is the one that cannot be skipped. Setup can be finished
 * without inviting anybody, but not without somewhere to post.
 */
final class OnboardingController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit,
        private readonly PrepareLedger $prepareLedger,
    ) {}

    public function show(): Response
    {
        $organization = $this->tenant->organization();

        return Inertia::render('Onboarding/Index', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'legal_name' => $organization->legal_name,
                'base_currency' => $organization->base_currency,
                'country_code' => $organization->country_code,
                'fiscal_year_start_month' => $organization->fiscal_year_start_month,
                'timezone' => $organization->timezone,
                'tax_registration_number' => $organization->tax_registration_number,
                'sales_tax_registration_number' => $organization->sales_tax_registration_number,
                'business_registration_number' => $organization->business_registration_number,
                'phone' => $organization->phone,
                'email' => $organization->email,
                'website' => $organization->website,
                'address' => $organization->address,
                'onboarding_complete' => $organization->hasCompletedOnboarding(),
            ],
            'options' => OrganizationOptions::forForms(),
            'ledger' => $this->ledgerState(),
            'canInvite' => $this->userCan(Permission::UsersInvite),
            'canPrepareLedger' => $this->userCan(Permission::AccountingManageAccounts),
        ]);
    }

    public function updateDetails(UpdateOnboardingDetailsRequest $request): RedirectResponse
    {
        $organization = $this->tenant->organization();
        $validated = $request->validated();

        DB::transaction(function () use ($organization, $validated, $request): void {
            $organization->fill($validated)->save();

            if ($organization->wasChanged()) {
                $this->audit->recordChange(
                    action: 'organization.updated',
                    subject: $organization,
                    description: 'Updated business details during setup',
                    actor: $request->user(),
                );
            }
        });

        return back()->with('success', 'Business details saved.');
    }

    /**
     * Finish setup.
     *
     * Idempotent: completing an already-complete organisation is a no-op
     * rather than a second audit entry, because the wizard is reachable by URL
     * and a refresh must not rewrite history.
     */
    public function complete(Request $request): RedirectResponse
    {
        $organization = $this->tenant->organization();

        if ($organization->hasCompletedOnboarding()) {
            return redirect()->route('dashboard');
        }

        /*
         * Setup cannot finish without a ledger. An organisation marked ready
         * that refuses every posting is worse than one still visibly in
         * setup — the error arrives later, on somebody's first invoice.
         */
        if (! $this->prepareLedger->isReady()) {
            return back()->with(
                'error',
                'Set up the chart of accounts and the financial year first — '.
                'without them nothing can be posted.',
            );
        }

        DB::transaction(function () use ($organization, $request): void {
            $organization->forceFill(['onboarding_completed_at' => now()])->save();

            $this->audit->record(
                action: 'organization.onboarding_completed',
                subject: $organization,
                description: "Finished setting up {$organization->name}",
                actor: $request->user(),
            );
        });

        return redirect()
            ->route('dashboard')
            ->with('success', "{$organization->name} is set up and ready.");
    }

    /**
     * Build the chart of accounts and the opening financial year.
     *
     * Idempotent, so a refresh, a resumed wizard or a double-submitted button
     * produces the same ledger rather than a second chart.
     */
    public function prepareLedger(Request $request): RedirectResponse
    {
        $this->authorize(Permission::AccountingManageAccounts->value);

        $result = $this->prepareLedger->handle(
            $this->tenant->organization(),
            $request->user(),
        );

        $message = $result['accounts_created'] === 0
            ? "Ledger already set up for {$result['fiscal_year']}."
            : "Created {$result['accounts_created']} accounts and opened {$result['fiscal_year']}.";

        return back()->with('success', $message);
    }

    /**
     * What the ledger step has to report.
     *
     * @return array{ready: bool, accounts: int, fiscal_year: string|null, jurisdiction: string}
     */
    private function ledgerState(): array
    {
        $organization = $this->tenant->organization();

        $year = FiscalYear::query()
            ->whereDate('starts_on', '<=', now())
            ->whereDate('ends_on', '>=', now())
            ->first();

        return [
            'ready' => $this->prepareLedger->isReady(),
            'accounts' => Account::query()->whereNull('archived_at')->count(),
            'fiscal_year' => $year?->label,
            'jurisdiction' => $organization->jurisdiction,
        ];
    }

    private function userCan(Permission $permission): bool
    {
        return request()->user()?->can($permission->value) ?? false;
    }
}
