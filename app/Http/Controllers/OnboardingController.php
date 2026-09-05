<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Access\Enums\Permission;
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
 * Phase 1 collects the business details that appear on documents and invites
 * the team. The tax configuration and chart of accounts steps are shown but
 * deferred: they need the ledger, which arrives in Phase 2. Showing them now
 * means the wizard does not change shape under users when they land.
 */
final class OnboardingController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit,
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
            'canInvite' => $this->userCan(Permission::UsersInvite),
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

    private function userCan(Permission $permission): bool
    {
        return request()->user()?->can($permission->value) ?? false;
    }
}
