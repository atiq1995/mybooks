<?php

declare(strict_types=1);

use App\Http\Controllers\Accounting\ChartOfAccountsController;
use App\Http\Controllers\Accounting\CurrencyController;
use App\Http\Controllers\Accounting\FiscalPeriodController;
use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Accounting\OpeningBalanceController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use App\Http\Controllers\AppearanceController;
use App\Http\Controllers\Banking\BankAccountController;
use App\Http\Controllers\Banking\BankReconciliationController;
use App\Http\Controllers\Banking\BankTransactionController;
use App\Http\Controllers\Banking\BankTransferController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Expenses\ExpenseController;
use App\Http\Controllers\Expenses\ReceiptController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Inventory\InventoryAdjustmentController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\StockTransferController;
use App\Http\Controllers\Inventory\WarehouseController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ModulePlaceholderController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\Purchases\PayablesReportController;
use App\Http\Controllers\Purchases\PurchaseDocumentController;
use App\Http\Controllers\Purchases\VendorPaymentController;
use App\Http\Controllers\Reports\AnalyticsController;
use App\Http\Controllers\Reports\FinancialStatementController;
use App\Http\Controllers\Reports\ReportIndexController;
use App\Http\Controllers\Reports\TaxSummaryController;
use App\Http\Controllers\Sales\ContactController;
use App\Http\Controllers\Sales\ItemController;
use App\Http\Controllers\Sales\PaymentController;
use App\Http\Controllers\Sales\ReceivablesReportController;
use App\Http\Controllers\Sales\RecurringInvoiceController;
use App\Http\Controllers\Sales\SalesDocumentController;
use App\Http\Controllers\Settings\AppearancePreferencesController;
use App\Http\Controllers\Settings\MemberController;
use App\Http\Controllers\Settings\MileageRateController;
use App\Http\Controllers\Settings\OrganizationSettingsController;
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

    /*
     * The organisation's own details. Reading needs `settings.view`; changing
     * them needs `settings.organization`, because a company's legal name and
     * tax numbers appear on every document it issues.
     */
    Route::get('/settings/organization', [OrganizationSettingsController::class, 'show'])
        ->name('settings.organization');
    Route::patch('/settings/organization', [OrganizationSettingsController::class, 'update'])
        ->name('settings.organization.update');

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

        /*
         * Opening balances. Its own permission, because it is the one write
         * that states a position nobody in this system produced — and it
         * happens once, usually by whoever set the organisation up.
         */
        Route::get('/opening-balances', [OpeningBalanceController::class, 'index'])
            ->name('opening-balances');
        Route::post('/opening-balances/accounts', [OpeningBalanceController::class, 'storeBalances'])
            ->name('opening-balances.accounts');
        Route::post('/opening-balances/invoices', [OpeningBalanceController::class, 'storeInvoice'])
            ->name('opening-balances.invoices');
        Route::post('/opening-balances/bills', [OpeningBalanceController::class, 'storeBill'])
            ->name('opening-balances.bills');
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
         * Recurring invoices: templates, not documents.
         *
         * Before the {type} routes below, which would otherwise match
         * 'recurring-invoices' as a document type.
         */
        Route::prefix('recurring-invoices')->name('recurring.')->group(function (): void {
            Route::get('/', [RecurringInvoiceController::class, 'index'])->name('index');
            Route::get('/new', [RecurringInvoiceController::class, 'edit'])->name('create');
            Route::post('/', [RecurringInvoiceController::class, 'store'])->name('store');
            Route::get('/{template}', [RecurringInvoiceController::class, 'show'])->name('show');
            Route::get('/{template}/edit', [RecurringInvoiceController::class, 'edit'])
                ->name('edit');
            Route::patch('/{template}', [RecurringInvoiceController::class, 'update'])
                ->name('update');
            Route::delete('/{template}', [RecurringInvoiceController::class, 'destroy'])
                ->name('destroy');
            Route::post('/{template}/status', [RecurringInvoiceController::class, 'status'])
                ->name('status');
            // Run one now rather than waiting for the scheduler. Safe to
            // press twice.
            Route::post('/{template}/generate', [RecurringInvoiceController::class, 'generate'])
                ->name('generate');
        });

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

    /*
     * Purchases.
     *
     * The same arrangement as sales: three document types sharing one
     * controller, with the type in the URL segment. Registered before the
     * placeholder catch-all, which matches /purchases and would otherwise
     * swallow all of it.
     */
    Route::prefix('purchases')->name('purchases.')->group(function (): void {
        Route::redirect('/', '/purchases/bills');

        // Vendors live on the contacts screens with the rest, filtered by
        // kind. Two directories of people who are often the same people is a
        // worse answer than one with a filter.
        Route::redirect('/vendors', '/sales/customers?kind=vendor');

        Route::get('/payments', [VendorPaymentController::class, 'index'])->name('payments.index');
        Route::get('/payments/new', [VendorPaymentController::class, 'create'])
            ->name('payments.create');
        Route::post('/payments', [VendorPaymentController::class, 'store'])->name('payments.store');
        // Its own endpoint so choosing a vendor does not reload the form and
        // lose what has already been typed.
        Route::get('/payments/outstanding/{contact}', [VendorPaymentController::class, 'outstanding'])
            ->name('payments.outstanding');

        Route::get('/payables', [PayablesReportController::class, 'index'])->name('payables');

        /*
         * The document routes, last within this group: the {type} segment
         * would otherwise match 'payments' and 'payables'.
         */
        Route::get('/{type}', [PurchaseDocumentController::class, 'index'])
            ->name('documents.index')
            ->where('type', 'orders|bills|vendor-credits');
        Route::get('/{type}/new', [PurchaseDocumentController::class, 'edit'])
            ->name('documents.create')
            ->where('type', 'orders|bills|vendor-credits');
        Route::post('/{type}', [PurchaseDocumentController::class, 'store'])
            ->name('documents.store')
            ->where('type', 'orders|bills|vendor-credits');
        Route::get('/{type}/{number}', [PurchaseDocumentController::class, 'show'])
            ->name('documents.show')
            ->where('type', 'orders|bills|vendor-credits');
        Route::get('/{type}/{number}/edit', [PurchaseDocumentController::class, 'edit'])
            ->name('documents.edit')
            ->where('type', 'orders|bills|vendor-credits');
        Route::patch('/{type}/{number}', [PurchaseDocumentController::class, 'update'])
            ->name('documents.update')
            ->where('type', 'orders|bills|vendor-credits');
        // "Approve", not "issue": on this side the word is the control.
        Route::post('/{type}/{number}/approve', [PurchaseDocumentController::class, 'approve'])
            ->name('documents.approve')
            ->where('type', 'orders|bills|vendor-credits');
        Route::post('/{type}/{number}/void', [PurchaseDocumentController::class, 'void'])
            ->name('documents.void')
            ->where('type', 'orders|bills|vendor-credits');
        Route::post('/{type}/{number}/convert', [PurchaseDocumentController::class, 'convert'])
            ->name('documents.convert')
            ->where('type', 'orders|bills|vendor-credits');
        Route::delete('/{type}/{number}', [PurchaseDocumentController::class, 'destroy'])
            ->name('documents.destroy')
            ->where('type', 'orders|bills|vendor-credits');
    });

    /*
     * Expenses.
     *
     * Flatter than sales and purchases, because there is one document type
     * rather than three or four. The workflow verbs are the routes that
     * matter here: submit, approve, reject — which is where an expense
     * differs from everything else in the product.
     */
    Route::prefix('expenses')->name('expenses.')->group(function (): void {
        Route::get('/', [ExpenseController::class, 'index'])->name('index');
        Route::get('/new', [ExpenseController::class, 'edit'])->name('create');
        Route::post('/', [ExpenseController::class, 'store'])->name('store');

        // Before /{number}, or the segment would swallow it.
        Route::post('/rebill', [ExpenseController::class, 'rebill'])->name('rebill');

        Route::get('/{number}', [ExpenseController::class, 'show'])->name('show');
        Route::get('/{number}/edit', [ExpenseController::class, 'edit'])->name('edit');
        Route::patch('/{number}', [ExpenseController::class, 'update'])->name('update');
        Route::delete('/{number}', [ExpenseController::class, 'destroy'])->name('destroy');

        Route::post('/{number}/submit', [ExpenseController::class, 'submit'])->name('submit');
        Route::post('/{number}/approve', [ExpenseController::class, 'approve'])->name('approve');
        Route::post('/{number}/reject', [ExpenseController::class, 'reject'])->name('reject');
        Route::post('/{number}/void', [ExpenseController::class, 'void'])->name('void');

        /*
         * Receipts stream through the application rather than from storage.
         * A presigned URL is a financial record that leaks with no audit
         * trail and no permission check.
         */
        Route::post('/{number}/receipts', [ReceiptController::class, 'store'])
            ->name('receipts.store');
        Route::get('/{number}/receipts/{attachment}', [ReceiptController::class, 'show'])
            ->name('receipts.show');
        Route::delete('/{number}/receipts/{attachment}', [ReceiptController::class, 'destroy'])
            ->name('receipts.destroy');
    });

    /*
     * Banking.
     *
     * The routes mirror the separation of duties rather than the tables:
     * importing is its own permission because it changes nothing, matching
     * and reconciling are another because they are the judgements the books
     * rest on, and transfers are a third because they are the only thing here
     * that posts.
     */
    Route::prefix('banking')->name('banking.')->group(function (): void {
        Route::get('/accounts', [BankAccountController::class, 'index'])->name('accounts');
        Route::post('/accounts', [BankAccountController::class, 'store'])->name('accounts.store');
        Route::patch('/accounts/{bankAccount}', [BankAccountController::class, 'update'])
            ->name('accounts.update');
        Route::delete('/accounts/{bankAccount}', [BankAccountController::class, 'archive'])
            ->name('accounts.archive');

        Route::get('/transactions', [BankTransactionController::class, 'index'])
            ->name('transactions');
        Route::post('/accounts/{bankAccount}/import', [BankTransactionController::class, 'import'])
            ->name('transactions.import');
        Route::post('/transactions/{line}/match', [BankTransactionController::class, 'match'])
            ->name('transactions.match');
        Route::post('/transactions/{line}/unmatch', [BankTransactionController::class, 'unmatch'])
            ->name('transactions.unmatch');
        Route::post('/transactions/{line}/exclude', [BankTransactionController::class, 'exclude'])
            ->name('transactions.exclude');

        Route::get('/reconciliation', [BankReconciliationController::class, 'index'])
            ->name('reconciliation');
        Route::post('/reconciliation', [BankReconciliationController::class, 'store'])
            ->name('reconciliation.store');
        Route::post('/reconciliation/{reconciliation}/complete', [BankReconciliationController::class, 'complete'])
            ->name('reconciliation.complete');
        Route::delete('/reconciliation/{reconciliation}', [BankReconciliationController::class, 'destroy'])
            ->name('reconciliation.abandon');

        Route::get('/transfers', [BankTransferController::class, 'index'])->name('transfers');
        Route::post('/transfers', [BankTransferController::class, 'store'])->name('transfers.store');
        Route::post('/transfers/{transfer}/void', [BankTransferController::class, 'void'])
            ->name('transfers.void');
    });

    /*
     * Inventory.
     *
     * The paths match `navigation.ts` exactly, because the sidebar links by
     * `match` rather than by route name — a screen at a different path would
     * leave the menu item pointing at a 404 the moment the module slug came
     * out of the placeholder allowlist below.
     *
     * Reading needs `inventory.view`; changing stock needs `inventory.adjust`,
     * and approving an adjustment needs `accounting.post` on top, because
     * approving it writes in the ledger.
     */
    Route::prefix('inventory')->name('inventory.')->group(function (): void {
        Route::get('/items', [StockController::class, 'items'])->name('items');
        Route::get('/items/{item}/movements', [StockController::class, 'movements'])
            ->name('items.movements');
        Route::get('/valuation', [StockController::class, 'valuation'])->name('valuation');

        Route::get('/warehouses', [WarehouseController::class, 'index'])->name('warehouses');
        Route::post('/warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        Route::patch('/warehouses/{warehouse}', [WarehouseController::class, 'update'])
            ->name('warehouses.update');
        Route::delete('/warehouses/{warehouse}', [WarehouseController::class, 'archive'])
            ->name('warehouses.archive');

        Route::get('/adjustments', [InventoryAdjustmentController::class, 'index'])
            ->name('adjustments');
        Route::post('/adjustments', [InventoryAdjustmentController::class, 'store'])
            ->name('adjustments.store');
        Route::get('/adjustments/{adjustment}', [InventoryAdjustmentController::class, 'show'])
            ->name('adjustments.show');
        Route::patch('/adjustments/{adjustment}', [InventoryAdjustmentController::class, 'update'])
            ->name('adjustments.update');
        Route::post('/adjustments/{adjustment}/approve', [InventoryAdjustmentController::class, 'approve'])
            ->name('adjustments.approve');
        Route::post('/adjustments/{adjustment}/void', [InventoryAdjustmentController::class, 'void'])
            ->name('adjustments.void');

        Route::get('/transfers', [StockTransferController::class, 'index'])->name('transfers');
        Route::post('/transfers', [StockTransferController::class, 'store'])->name('transfers.store');
        Route::post('/transfers/{transfer}/complete', [StockTransferController::class, 'complete'])
            ->name('transfers.complete');
    });

    /*
     * Reports.
     *
     * Exporting is a FORMAT on the same URL rather than a route of its own —
     * `?format=csv`, `xlsx` or `print`. That is what makes an exported figure
     * provably the same figure as the one on screen: there is one controller
     * action, one report object, and one set of filters behind all four.
     */
    Route::prefix('reports')->name('reports.')->group(function (): void {
        Route::get('/', ReportIndexController::class)->name('index');

        Route::get('/profit-and-loss', [FinancialStatementController::class, 'profitAndLoss'])
            ->name('profit-and-loss');
        Route::get('/balance-sheet', [FinancialStatementController::class, 'balanceSheet'])
            ->name('balance-sheet');
        Route::get('/cash-flow', [FinancialStatementController::class, 'cashFlow'])
            ->name('cash-flow');

        Route::get('/tax-summary', TaxSummaryController::class)->name('tax-summary');
        Route::get('/analytics', AnalyticsController::class)->name('analytics');
    });

    // Mileage rates. Configuration, and dated like a tax rate.
    Route::get('/settings/mileage', [MileageRateController::class, 'index'])
        ->name('settings.mileage');
    Route::post('/settings/mileage', [MileageRateController::class, 'store'])
        ->name('settings.mileage.store');

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
        ->where('module', 'contacts|documents|settings|organizations')
        ->where('submodule', '[a-z0-9\-]+')
        ->name('module.placeholder');
});
