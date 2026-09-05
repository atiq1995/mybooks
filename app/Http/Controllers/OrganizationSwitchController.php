<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Organizations\Enums\MembershipStatus;
use App\Domain\Organizations\Models\Organization;
use App\Http\Middleware\EstablishTenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switch the active organisation.
 *
 * Membership is verified here, and again on every subsequent request by
 * EstablishTenantContext. Checking twice is deliberate: this check decides
 * whether the switch is allowed, and that one decides whether it is *still*
 * allowed — which matters when access is revoked mid-session.
 */
final class OrganizationSwitchController extends Controller
{
    public function __invoke(Request $request, Organization $organization): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $isMember = $organization->memberships()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', MembershipStatus::Active->value)
            ->exists();

        // 404 rather than 403: telling a stranger that an organisation exists
        // but is closed to them is itself information.
        abort_unless($isMember && ! $organization->isArchived(), 404);

        $request->session()->put(EstablishTenantContext::SESSION_KEY, $organization->getKey());

        // Remembered so the next sign-in lands where they left off.
        $user->forceFill(['last_organization_id' => $organization->getKey()])->save();

        /*
         * A full redirect rather than back(): every cached Inertia prop on the
         * previous page belongs to the organisation being left behind, and
         * showing one organisation's figures under another's name — even for
         * a frame — is not acceptable in an accounting product.
         */
        return redirect()->route('dashboard')
            ->with('success', "Switched to {$organization->name}");
    }
}
