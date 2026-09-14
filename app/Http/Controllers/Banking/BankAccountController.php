<?php

declare(strict_types=1);

namespace App\Http\Controllers\Banking;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Models\Account;
use App\Domain\Banking\Actions\SaveBankAccount;
use App\Domain\Banking\Enums\BankAccountKind;
use App\Domain\Banking\Exceptions\BankingRefused;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Services\ReconciliationCalculator;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bank, cash and credit-card accounts.
 *
 * Every balance on this screen comes from the ledger. There is no cached
 * total on a bank account and no figure computed in the browser: the chart of
 * accounts is where an account's balance lives, and a second copy of it here
 * would disagree the first time anything posted around it.
 */
final class BankAccountController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SaveBankAccount $saveBankAccount,
        private readonly ReconciliationCalculator $calculator,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::BankingView->value);

        $organization = $this->tenant->organization();
        $today = Carbon::now()->toDateString();

        $accounts = BankAccount::query()
            ->with('account:id,code,name,type')
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        $balances = $this->balances();

        return Inertia::render('Banking/Accounts', [
            'accounts' => array_values($accounts
                ->map(function (BankAccount $bankAccount) use ($balances, $today): array {
                    $ledgerAccount = $bankAccount->account;

                    [, $unpresentedCount, $unpresentedTotal] =
                        $this->calculator->ledgerSide($bankAccount, $today);

                    return [
                        'id' => $bankAccount->id,
                        'name' => $bankAccount->name,
                        'label' => $bankAccount->label(),
                        'bank_name' => $bankAccount->bank_name,
                        'account_number_masked' => $bankAccount->account_number_masked,
                        'branch' => $bankAccount->branch,
                        'kind' => $bankAccount->kind->value,
                        'kind_label' => $bankAccount->kind->label(),
                        'currency' => $bankAccount->currency,
                        'is_active' => $bankAccount->is_active,
                        'is_primary' => $bankAccount->is_primary,
                        'is_archived' => $bankAccount->archived_at !== null,
                        'supports_statements' => $bankAccount->kind->hasStatements(),
                        'account' => [
                            'id' => $ledgerAccount?->id,
                            'code' => $ledgerAccount?->code,
                            'name' => $ledgerAccount?->name,
                        ],
                        'balance' => $balances[$bankAccount->account_id] ?? '0.0000',
                        'unpresented_count' => $unpresentedCount,
                        'unpresented_total' => $unpresentedTotal,
                        'reconciled_through' => $this->calculator
                            ->reconciledThrough($bankAccount)?->toDateString(),
                        'notes' => $bankAccount->notes,
                    ];
                })
                ->all()),
            'ledgerAccounts' => $this->ledgerAccountOptions(),
            'kinds' => array_values(array_map(
                static fn (BankAccountKind $kind): array => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                    'account_type' => $kind->requiredAccountType(),
                ],
                BankAccountKind::cases(),
            )),
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permission::BankingManageAccounts->value);

        $validated = $this->validated($request);

        try {
            $bankAccount = $this->saveBankAccount->handle($validated, actor: $request->user());
        } catch (BankingRefused $exception) {
            return back()->withErrors(['account_id' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', "{$bankAccount->name} added.");
    }

    public function update(Request $request, string $bankAccount): RedirectResponse
    {
        $this->authorize(Permission::BankingManageAccounts->value);

        $model = BankAccount::query()->findOrFail($bankAccount);

        $validated = $this->validated($request);

        try {
            $model = $this->saveBankAccount->handle($validated, $model, $request->user());
        } catch (BankingRefused $exception) {
            return back()->withErrors(['account_id' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', "{$model->name} updated.");
    }

    /**
     * Archive rather than delete.
     *
     * Its statement lines, matches and reconciliations are the evidence for
     * periods that are already closed, and deleting the account would take
     * them with it.
     */
    public function archive(Request $request, string $bankAccount): RedirectResponse
    {
        $this->authorize(Permission::BankingManageAccounts->value);

        $model = BankAccount::query()->findOrFail($bankAccount);

        $this->saveBankAccount->archive($model, $request->user());

        return back()->with('success', "{$model->name} archived. Its history is kept.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'account_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            /*
             * Accepted, immediately masked, and never stored in full. The
             * form says so, and the action does the masking so no other entry
             * point can skip it.
             */
            'account_number' => ['nullable', 'string', 'max:40'],
            'branch' => ['nullable', 'string', 'max:120'],
            'kind' => ['required', Rule::enum(BankAccountKind::class)],
            'is_active' => ['boolean'],
            'is_primary' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $validated;
    }

    /**
     * The balance of every account a bank account could be attached to.
     *
     * One query rather than one per account: this screen is a list, and a
     * balance per row would be a query per row.
     *
     * @return array<string, string>
     */
    private function balances(): array
    {
        /** @var list<object{account_id: string, balance: string}> $rows */
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('bank_accounts as ba', 'ba.account_id', '=', 'jl.account_id')
            ->where('je.status', 'posted')
            ->groupBy('jl.account_id')
            ->select('jl.account_id')
            ->selectRaw('COALESCE(SUM(jl.debit - jl.credit), 0) AS balance')
            ->get()
            ->all();

        $balances = [];

        foreach ($rows as $row) {
            $balances[$row->account_id] = (string) $row->balance;
        }

        return $balances;
    }

    /**
     * Accounts a bank account may be attached to.
     *
     * Assets and liabilities only, and never a heading. The form filters
     * further by kind, but the list itself is already narrow enough that
     * nobody has to scroll past revenue to find their current account.
     *
     * @return list<array{value: string, label: string, type: string, taken: bool}>
     */
    private function ledgerAccountOptions(): array
    {
        $taken = BankAccount::query()->pluck('account_id')->all();

        return array_values(Account::query()
            ->postable()
            ->whereIn('type', ['asset', 'liability'])
            ->orderBy('code')
            ->get()
            ->map(static fn (Account $account): array => [
                'value' => $account->id,
                'label' => "{$account->code} · {$account->name}",
                'type' => $account->type->value,
                'taken' => in_array($account->id, $taken, true),
            ])
            ->all());
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(Request $request): array
    {
        $user = $request->user();

        return [
            'manage' => $user?->can(Permission::BankingManageAccounts->value) ?? false,
            'import' => $user?->can(Permission::BankingImport->value) ?? false,
            'reconcile' => $user?->can(Permission::BankingReconcile->value) ?? false,
            'transfer' => $user?->can(Permission::BankingTransfer->value) ?? false,
        ];
    }
}
