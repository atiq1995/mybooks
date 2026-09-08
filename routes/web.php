<?php

declare(strict_types=1);

use App\Http\Controllers\Accounting\ChartOfAccountsController;
use App\Http\Controllers\Accounting\CurrencyController;
use App\Http\Controllers\Accounting\FiscalPeriodController;
use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use App\Http\Controllers\AppearanceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ModulePlaceholderController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\Sales\ContactController;
use App\Http\Controllers\Sales\ItemController;
use App\Http\Controllers\Sales\PaymentController;
use App\Http\Controllers\Sales\ReceivablesReportController;
use App\Http\Controllers\Sales\SalesDocumentController;
use App\Http\Controllers\Settings\AppearancePreferencesController;
use App\Http\Controllers\Settings\MemberController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TaxController;
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
    Route::post('/onboarding/ledger', [OnboardingController::class, 'prepareLedger'])
        ->name('onboarding.ledger');
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])
        ->name('onboarding.complete');

    // Theme and density. Its own tiny endpoint so a preference change never
    // re-renders the page the user is working on.
    Route::patch('/settings/appearance', AppearanceController::class)->name('settings.appearance');

    /*
     * Settings screens.
     *
     * Registered before the module placeholder catch-all below, which would
     * otherwise swallow every /settings/* path.
     */
    // /settings has no screen of its own — the first real one stands in for
    // it, rather than a placeholder telling the user settings are unbuilt.
    Route::redirect('/settings', '/settings/profile');

    Route::get('/settings/profile', [ProfileController::class, 'show'])->name('settings.profile');
    Route::get('/settings/security', [SecurityController::class, 'show'])->name('settings.security');
    Route::get('/settings/appearance-preferences', [AppearancePreferencesController::class, 'show'])
        ->name('settings.appearance-preferences');

    // Who has access, and what they may do.
    Route::get('/settings/members', [MemberController::class, 'index'])->name('settings.members');
    Route::post('/settings/members', [MemberController::class, 'invite'])
        ->name('settings.members.invite');
    Route::patch('/settings/members/{member}', [MemberController::class, 'updateRole'])
        ->name('settings.members.role');
    Route::delete('/settings/members/{member}', [MemberController::class, 'destroy'])
        ->name('settings.members.destroy');

    /*
     * Accounting.
     *
     * Registered before the placeholder catch-all, which matches /accounting
     * and would otherwise swallow every route here.
     *
     * Reads need `accounting.view`; writes need their own permission and are
     * authorised in the controller rather than by middleware, so the reason
     * for a refusal can name the account or period involved.
     */
    Route::prefix('accounting')->name('accounting.')->group(function (): void {
        // The section header in the sidebar points here; the chart is where
        // anybody opening "Accounting" cold actually wants to start.
        Route::redirect('/', '/accounting/accounts');

        Route::get('/accounts', [ChartOfAccountsController::class, 'index'])->name('accounts');
        Route::post('/accounts', [ChartOfAccountsController::class, 'store'])->name('accounts.store');
        Route::patch('/accounts/{account}', [ChartOfAccountsController::class, 'update'])
            ->name('accounts.update');
        // Archive, never delete: an account that has posted is referenced by
        // history that must stay readable.
        Route::delete('/accounts/{account}', [ChartOfAccountsController::class, 'archive'])
            ->name('accounts.archive');
        Route::post('/accounts/{account}/restore', [ChartOfAccountsController::class, 'restore'])
            ->name('accounts.restore');

        Route::get('/journals', [JournalController::class, 'index'])->name('journals');
        Route::get('/journals/new', [JournalController::class, 'create'])->name('journals.create');
        Route::post('/journals', [JournalController::class, 'store'])->name('journals.store');
        // Bound by entry number, which is what appears on every report and is
        // unique per organisation.
        Route::get('/journals/{entryNo}', [JournalController::class, 'show'])->name('journals.show');
        Route::post('/journals/{entryNo}/reverse', [JournalController::class, 'reverse'])
            ->name('journals.reverse');

        Route::get('/general-ledger', [GeneralLedgerController::class, 'index'])->name('general-ledger');
        Route::get('/trial-balance', [TrialBalanceController::class, 'index'])->name('trial-balance');

        Route::get('/currencies', [CurrencyController::class, 'index'])->name('currencies');
        Route::post('/currencies/rates', [CurrencyController::class, 'storeRate'])
            ->name('currencies.rates.store');
        Route::post('/currencies/revalue', [CurrencyController::class, 'revalue'])
            ->name('currencies.revalue');

        Route::get('/periods', [FiscalPeriodController::class, 'index'])->name('periods');
        Route::patch('/periods/{period}', [FiscalPeriodController::class, 'updateStatus'])
            ->name('periods.status');
        Route::post('/fiscal-years', [FiscalPeriodController::class, 'storeYear'])
            ->name('fiscal-years.store');
        // The close needs its own permission: it summarises twelve months into
        // one equity figure, and is the last act before filing.
        Route::post('/fiscal-years/{year}/close', [FiscalPeriodController::class, 'closeYear'])
            ->name('fiscal-years.close');
    });

    /*
     * Sales.
     *
     * The four document types share one controller and one set of routes,
     * because they share one model — the type arrives as a URL segment, so
     * /sales/invoices and /sales/estimates are the same code with a different
     * label. Registered before the placeholder catch-all, which matches
     * /sales and would otherwise swallow all of it.
     */
    Route::prefix('sales')->name('sales.')->group(function (): void {
        Route::redirect('/', '/sales/invoices');

        // Customers and vendors. Under sales because that is where people
        // look for them; the vendor filter serves purchases too.
        Route::get('/customers', [ContactController::class, 'index'])->name('contacts.index');
        Route::post('/customers', [ContactController::class, 'store'])->name('contacts.store');
        Route::get('/customers/{contact}', [ContactController::class, 'show'])->name('contacts.show');
        Route::patch('/customers/{contact}', [ContactController::class, 'update'])
            ->name('contacts.update');
        // Archive, never delete: a contact with documents is referenced by
        // history that has to stay readable.
        Route::delete('/customers/{contact}', [ContactController::class, 'archive'])
            ->name('contacts.archive');
        Route::post('/customers/{contact}/restore', [ContactController::class, 'restore'])
            ->name('contacts.restore');

        Route::get('/items', [ItemController::class, 'index'])->name('items.index');
        Route::post('/items', [ItemController::class, 'store'])->name('items.store');
        Route::patch('/items/{item}', [ItemController::class, 'update'])->name('items.update');
        Route::delete('/items/{item}', [ItemController::class, 'archive'])->name('items.archive');
        Route::post('/items/{item}/restore', [ItemController::class, 'restore'])
            ->name('items.restore');

        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('/payments/new', [PaymentController::class, 'create'])->name('payments.create');
        Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
        // Its own endpoint so choosing a customer does not reload the form
        // and lose what has already been typed.
        Route::get('/payments/outstanding/{contact}', [PaymentController::class, 'outstanding'])
            ->name('payments.outstanding');

        Route::get('/receivables', [ReceivablesReportController::class, 'index'])->name('receivables');

        /*
         * The document routes, last within this group: the {type} segment
         * would otherwise match 'customers', 'items' and the rest.
         */
        Route::get('/{type}', [SalesDocumentController::class, 'index'])
            ->name('documents.index')
            ->where('type', 'estimates|sales-orders|invoices|credit-notes');
        Route::get('/{type}/new', [SalesDocumentController::class, 'edit'])
            ->name('documents.create')
            ->where('type', 'estimates|sales-orders|invoices|credit-notes');
        Route::post('/{type}', [SalesDocumentController::class, 'store'])
            ->name('documents.store')
            ->where('type', 'estimates|sales-orders|invoices|credit-notes');
        Route::get('/{type}/{number}', [SalesDocumentController::class, 'show'])
            ->name('documents.show')
            ->where('type', 'estimates|sales-orders|invoices|credit-notes');
        Route::get('/{type}/{number}/edit', [SalesDocumentController::class, 'edit'])
            ->name('documents.edit')
            ->where('type', 'estimates|sales-orders|invoices|credit-notes');
        Route::patch('/{type}/{number}', [SalesDocumentController::class, 'update'])
            ->name('documents.update')
            ->where('type', 'estimates|sales-orders|invoices|credit-notes');
        Route::post('/{type}/{number}/issue', [SalesDocumentController::class, 'issue'])
            ->name('documents.issue')
            ->where('type', 'estimates|sales-orders|invoices|credit-notes');
        Route::post('/{type}/{number}/void', [SalesDocumentController::class, 'void'])
            ->name('documents.void')
            ->where('type', 'estimates|sales-orders|invoices|credit-notes');
        Route::post('/{type}/{number}/convert', [SalesDocumentController::class, 'convert'])
            ->name('documents.convert')
            ->where('type', 'estimates|sales-orders|invoices|credit-notes');
        Route::delete('/{type}/{number}', [SalesDocumentController::class, 'destroy'])
            ->name('documents.destroy')
            ->where('type', 'estimates|sales-orders|invoices|credit-notes');
    });

    // Tax rates. Under settings because they are configuration, not a
    // day-to-day screen — but the sales module cannot work without them.
    Route::get('/settings/taxes', [TaxController::class, 'index'])->name('settings.taxes');
    Route::post('/settings/taxes', [TaxController::class, 'store'])->name('settings.taxes.store');
    Route::patch('/settings/taxes/{tax}', [TaxController::class, 'update'])
        ->name('settings.taxes.update');
    Route::delete('/settings/taxes/{tax}', [TaxController::class, 'archive'])
        ->name('settings.taxes.archive');
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
