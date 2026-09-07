<?php

declare(strict_types=1);

use App\Http\Controllers\AppearanceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ModulePlaceholderController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\Settings\MemberController;
use Illuminate\Support\Facades\Route;

/*
|---------------------------------------------------------------------------
| Web routes
|---------------------------------------------------------------------------
|
| Authentication routes are registered by Laravel Fortify — see
| FortifyServiceProvider, which maps them onto Inertia pages.
|
| Module routes arrive in their own phases and will move into per-module
| route files as they do. See ROADMAP.md.
*/

// Dependency health, for load balancers and uptime monitoring. Deliberately
// unauthenticated but detail-free — it reports up or down, never why.
Route::get('/health', HealthController::class)->name('health');

/*
 * Invitations are reachable while signed OUT on purpose. Open registration is
 * off, so an invitation is the only route to an account in a default
 * deployment — and its recipient usually has none yet.
 *
 * Throttled: the token is a credential, and these routes are the one place it
 * can be guessed at.
 */
Route::middleware('throttle:10,1')->group(function (): void {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])
        ->name('invitations.show');
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])
        ->name('invitations.accept');
    Route::post('/invitations/{token}/register', [InvitationController::class, 'register'])
        ->name('invitations.register');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::redirect('/', '/dashboard');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    /*
     * Organisations.
     *
     * Creation sits OUTSIDE any organisation context — at that point the user
     * may belong to none at all.
     */
    Route::get('/organizations/create', [OrganizationController::class, 'create'])
        ->name('organizations.create');
    Route::post('/organizations', [OrganizationController::class, 'store'])
        ->name('organizations.store');

    // A POST because it changes server-side session state; a GET would be
    // pre-fetchable and CSRF-exposed.
    Route::post('/organizations/{organization:slug}/switch', OrganizationSwitchController::class)
        ->name('organizations.switch');

    /*
     * Setup wizard. Until it finishes, the organisation has no chart of
     * accounts and cannot post anything.
     */
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::patch('/onboarding/details', [OnboardingController::class, 'updateDetails'])
        ->name('onboarding.details');
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])
        ->name('onboarding.complete');

    // Theme and density. Its own tiny endpoint so a preference change never
    // re-renders the page the user is working on.
    Route::patch('/settings/appearance', AppearanceController::class)->name('settings.appearance');

    // Who has access, and what they may do.
    Route::get('/settings/members', [MemberController::class, 'index'])->name('settings.members');
    Route::post('/settings/members', [MemberController::class, 'invite'])
        ->name('settings.members.invite');
    Route::patch('/settings/members/{member}', [MemberController::class, 'updateRole'])
        ->name('settings.members.role');
    Route::delete('/settings/members/{member}', [MemberController::class, 'destroy'])
        ->name('settings.members.destroy');

    /*
     * Everything the navigation advertises but that has not been built yet.
     *
     * A designed "arriving in Phase N" page rather than a dead link or a 404:
     * the information architecture is real from day one, and the product is
     * honest about which parts of it work.
     *
     * Registered LAST so that every real route above wins — this pattern would
     * otherwise swallow /organizations/create and /settings/appearance.
     *
     * Each module replaces its entry here as it lands.
     */
    Route::get('/{module}/{submodule?}', ModulePlaceholderController::class)
        ->where('module', 'sales|purchases|expenses|banking|accounting|inventory|reports|contacts|documents|settings|organizations')
        ->where('submodule', '[a-z0-9\-]+')
        ->name('module.placeholder');
});
