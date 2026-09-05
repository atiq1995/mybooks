<?php

declare(strict_types=1);

use App\Http\Controllers\AppearanceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ModulePlaceholderController;
use App\Http\Controllers\OrganizationSwitchController;
use Illuminate\Support\Facades\Route;

/*
|---------------------------------------------------------------------------
| Web routes
|---------------------------------------------------------------------------
|
| Phase 0 ships the shell: authentication, the dashboard, and a placeholder
| for every module the navigation advertises. Authentication routes
| themselves are registered by Laravel Fortify — see FortifyServiceProvider,
| which maps them onto Inertia pages.
|
| Module routes arrive in their own phases and will move into per-module
| route files as they do. See ROADMAP.md.
*/

// Dependency health, for load balancers and uptime monitoring. Deliberately
// unauthenticated but detail-free — it reports up or down, never why.
Route::get('/health', HealthController::class)->name('health');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::redirect('/', '/dashboard');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Switching organisation is a POST because it changes server-side session
    // state. A GET would be pre-fetchable and CSRF-exposed.
    Route::post('/organizations/{organization:slug}/switch', OrganizationSwitchController::class)
        ->name('organizations.switch');

    // Theme and density. Its own tiny endpoint so a preference change never
    // re-renders the page the user is working on.
    Route::patch('/settings/appearance', AppearanceController::class)->name('settings.appearance');

    /*
     * Everything the navigation advertises but has not been built yet.
     *
     * A designed "arriving in Phase N" page rather than a dead link or a 404:
     * the information architecture is real from day one, and the product is
     * honest about which parts of it work.
     *
     * Each module replaces its entry here as it lands.
     */
    Route::get('/{module}/{submodule?}', ModulePlaceholderController::class)
        ->where('module', 'sales|purchases|expenses|banking|accounting|inventory|reports|contacts|documents|settings|organizations')
        ->where('submodule', '[a-z0-9\-]+')
        ->name('module.placeholder');
});
