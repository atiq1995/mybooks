<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Models\Account;
use App\Domain\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreAccountRequest;
use App\Http\Requests\Accounting\UpdateAccountRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The chart of accounts.
 *
 * Presented as a tree with running balances, because the question an
 * accountant actually brings to this screen is "what is in 1200 right now",
 * not "list my accounts".
 *
 * Balances are summed from journal lines on every request rather than cached
 * on the row. A cached balance is a second source of truth, and the first
 * time it drifts from the ledger nobody can tell which one is wrong.
 *
 * @see ACCOUNTING_RULES.md §3
 */
final class ChartOfAccountsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::AccountingView->value);

        $organization = $this->tenant->organization();

        $showArchived = $request->boolean('archived');

        $accounts = Account::query()
            ->when(! $showArchived, fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('code')
            ->get();

        $balances = $this->balancesByAccount();

        return Inertia::render('Accounting/Accounts/Index', [
            'accounts' => $accounts->map(fn (Account $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'description' => $account->description,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'subtype' => $account->subtype,
                'normal_balance' => $account->normal_balance->value,
                'parent_id' => $account->parent_id,
                'system_role' => $account->system_role,
                'currency' => $account->currency,
                'is_active' => $account->is_active,
                'is_header' => $account->is_header,
                'is_archived' => $account->archived_at !== null,
                // Signed by normal balance, so a positive figure always means
                // "as expected for this kind of account".
                'balance' => $account->is_header
                    ? null
                    : ($balances[$account->id] ?? '0.0000'),
            ])->values()->all(),
            'baseCurrency' => $organization->base_currency,
            'showArchived' => $showArchived,
            'options' => [
                'types' => array_map(
                    static fn (AccountType $type): array => [
                        'value' => $type->value,
                        'label' => $type->label(),
                        'normal_balance' => $type->normalBalance()->value,
                    ],
                    AccountType::cases(),
                ),
                'normal_balances' => array_map(
                    static fn (NormalBalance $balance): array => [
                        'value' => $balance->value,
                        'label' => $balance->label(),
                    ],
                    NormalBalance::cases(),
                ),
            ],
            'can' => [
                'manage' => $request->user()?->can(Permission::AccountingManageAccounts->value) ?? false,
            ],
        ]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $this->authorize(Permission::AccountingManageAccounts->value);

        $validated = $request->validated();
        $code = (string) $request->string('code');

        DB::transaction(function () use ($validated, $request): void {
            $account = new Account;

            $account->forceFill([
                'organization_id' => $this->tenant->organization()->id,
                'code' => $validated['code'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'type' => $validated['type'],
                'subtype' => $validated['subtype'] ?? null,
                'normal_balance' => $validated['normal_balance'],
                'parent_id' => $validated['parent_id'] ?? null,
                'currency' => $validated['currency'] ?? null,
                'is_header' => $validated['is_header'] ?? false,
                'is_active' => true,
                // A user-created account never claims a system role: those
                // are the machinery's own accounts, and two claimants is
                // unresolvable.
                'system_role' => null,
            ])->save();

            $this->audit->record(
                action: 'accounting.account_created',
                subject: $account,
                description: "Created account {$account->code} {$account->name}",
                new: [
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type->value,
                    'normal_balance' => $account->normal_balance->value,
                ],
                actor: $request->user(),
            );
        });

        return back()->with('success', "Account {$code} created.");
    }

    public function update(UpdateAccountRequest $request, Account $account): RedirectResponse
    {
        $this->authorize(Permission::AccountingManageAccounts->value);

        $this->guardBelongsToActiveOrganization($account);

        $validated = $request->validated();

        DB::transaction(function () use ($account, $validated, $request): void {
            /*
             * Name, description and grouping are editable. Type and normal
             * balance are not, once anything has posted: changing them
             * retroactively flips the sign of every figure the account has
             * ever contributed to a report.
             */
            $account->fill([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'parent_id' => $validated['parent_id'] ?? null,
            ]);

            if (! $account->isSystemAccount() && ! $this->hasPostings($account)) {
                $account->fill([
                    'type' => $validated['type'],
                    'normal_balance' => $validated['normal_balance'],
                ]);
            }

            $account->save();

            if ($account->wasChanged()) {
                $this->audit->recordChange(
                    action: 'accounting.account_updated',
                    subject: $account,
                    description: "Updated account {$account->code} {$account->name}",
                    actor: $request->user(),
                );
            }
        });

        return back()->with('success', "Account {$account->code} updated.");
    }

    /**
     * Archive an account.
     *
     * Never a delete. An account that has posted to the ledger is referenced
     * by history, and removing it would orphan entries that must stay
     * readable for as long as the books are kept. Archiving hides it from
     * pickers while leaving every past figure intact.
     */
    public function archive(Request $request, Account $account): RedirectResponse
    {
        $this->authorize(Permission::AccountingManageAccounts->value);

        $this->guardBelongsToActiveOrganization($account);

        if ($account->isSystemAccount()) {
            return back()->with(
                'error',
                "{$account->code} {$account->name} is a system account. ".
                'The application posts to it automatically, so it cannot be archived.',
            );
        }

        if ($account->children()->whereNull('archived_at')->exists()) {
            return back()->with(
                'error',
                "{$account->code} {$account->name} still groups active accounts. ".
                'Archive or move them first.',
            );
        }

        DB::transaction(function () use ($account, $request): void {
            $account->forceFill([
                'archived_at' => now(),
                'is_active' => false,
            ])->save();

            $this->audit->record(
                action: 'accounting.account_archived',
                subject: $account,
                description: "Archived account {$account->code} {$account->name}",
                actor: $request->user(),
            );
        });

        return back()->with('success', "Account {$account->code} archived.");
    }

    public function restore(Request $request, Account $account): RedirectResponse
    {
        $this->authorize(Permission::AccountingManageAccounts->value);

        $this->guardBelongsToActiveOrganization($account);

        DB::transaction(function () use ($account, $request): void {
            $account->forceFill([
                'archived_at' => null,
                'is_active' => true,
            ])->save();

            $this->audit->record(
                action: 'accounting.account_restored',
                subject: $account,
                description: "Restored account {$account->code} {$account->name}",
                actor: $request->user(),
            );
        });

        return back()->with('success', "Account {$account->code} restored.");
    }

    /**
     * Every account's signed balance, in one query.
     *
     * One query rather than one per account: a full chart is 40-plus rows and
     * an N+1 here is the difference between a page that opens and a page that
     * hangs.
     *
     * @return array<string, string>
     */
    private function balancesByAccount(): array
    {
        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.status', 'posted')
            ->groupBy('journal_lines.account_id', 'accounts.normal_balance')
            ->selectRaw('journal_lines.account_id')
            ->selectRaw('accounts.normal_balance')
            ->selectRaw('COALESCE(SUM(journal_lines.debit_base), 0) AS debits')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_base), 0) AS credits')
            ->get();

        $balances = [];

        foreach ($rows as $row) {
            /** @var object{account_id: string, normal_balance: string, debits: string, credits: string} $row */
            $balances[$row->account_id] = NormalBalance::from($row->normal_balance)
                ->signedBalance((string) $row->debits, (string) $row->credits);
        }

        return $balances;
    }

    private function hasPostings(Account $account): bool
    {
        return $account->journalLines()->exists();
    }

    /**
     * Route binding resolves by code, which is unique per organisation — but
     * uniqueness per organisation is not uniqueness globally, so the match has
     * to be confirmed against the active tenant.
     *
     * A 404 rather than a 403: whether an account exists in somebody else's
     * organisation is not this user's business.
     */
    private function guardBelongsToActiveOrganization(Account $account): void
    {
        abort_unless(
            $account->organization_id === $this->tenant->organization()->id,
            404,
        );
    }
}
