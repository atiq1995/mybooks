<?php

declare(strict_types=1);

namespace App\Http\Controllers\Banking;

use App\Domain\Access\Enums\Permission;
use App\Domain\Banking\Actions\CompleteReconciliation;
use App\Domain\Banking\Actions\StartReconciliation;
use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Banking\Exceptions\BankingRefused;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankReconciliation;
use App\Domain\Banking\Services\ReconciliationCalculator;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reconciling an account to a statement.
 *
 * The screen's job is to make the difference — and where it is coming from —
 * impossible to miss, because a reconciliation that quietly rounds to zero is
 * worse than none at all. Four figures are always on screen, and two more
 * explain them: what the ledger says, and what of ours the bank has not seen.
 *
 * Completing is refused unless the difference is zero, and the refusal comes
 * from the domain rather than from a disabled button.
 */
final class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly StartReconciliation $startReconciliation,
        private readonly CompleteReconciliation $completeReconciliation,
        private readonly ReconciliationCalculator $calculator,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::BankingView->value);

        $organization = $this->tenant->organization();

        $accounts = BankAccount::query()
            ->usable()
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        $selected = $this->selectedAccount($request, array_values($accounts->all()));

        $open = $selected === null ? null : BankReconciliation::query()
            ->where('bank_account_id', $selected->getKey())
            ->where('status', ReconciliationStatus::Draft)
            ->first();

        return Inertia::render('Banking/Reconciliation', [
            'accounts' => array_values($accounts
                ->map(static fn (BankAccount $account): array => [
                    'value' => $account->id,
                    'label' => $account->label(),
                    'currency' => $account->currency,
                ])
                ->all()),
            'account' => $selected === null ? null : [
                'id' => $selected->id,
                'name' => $selected->name,
                'label' => $selected->label(),
                'currency' => $selected->currency,
                'reconciled_through' => $this->calculator
                    ->reconciledThrough($selected)?->toDateString(),
                'suggested_opening' => $this->calculator->openingFor($selected, Carbon::now()),
            ],
            'open' => $open === null ? null : [
                ...$this->present($open),
                'figures' => $this->calculator->figuresFor($open),
            ],
            'history' => $selected === null ? [] : $this->history($selected),
            'today' => Carbon::now()->toDateString(),
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permission::BankingReconcile->value);

        $request->validate([
            'bank_account_id' => ['required', 'uuid'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            // Decimal strings, and validated as such: a statement balance can
            // legitimately be negative, so no `min:0` here.
            'closing_balance' => ['required', 'numeric'],
            'opening_balance' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $account = BankAccount::query()
            ->findOrFail($request->string('bank_account_id')->toString());

        try {
            $reconciliation = $this->startReconciliation->handle(
                bankAccount: $account,
                periodStart: Carbon::parse($request->string('period_start')->toString()),
                periodEnd: Carbon::parse($request->string('period_end')->toString()),
                closingBalance: $request->string('closing_balance')->toString(),
                openingBalance: $request->string('opening_balance')->toString() ?: null,
                notes: $request->string('notes')->toString() ?: null,
                actor: $request->user(),
            );
        } catch (BankingRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Reconciliation {$reconciliation->number} started.");
    }

    public function complete(Request $request, string $reconciliation): RedirectResponse
    {
        $this->authorize(Permission::BankingReconcile->value);

        $model = BankReconciliation::query()->findOrFail($reconciliation);

        try {
            $completed = $this->completeReconciliation->handle($model, $request->user());
        } catch (BankingRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            '%s completed at %s. Its lines are now a record and cannot be changed.',
            $completed->number,
            $completed->period_end->toDateString(),
        ));
    }

    public function destroy(Request $request, string $reconciliation): RedirectResponse
    {
        $this->authorize(Permission::BankingReconcile->value);

        $model = BankReconciliation::query()->findOrFail($reconciliation);

        try {
            $this->startReconciliation->abandon($model, $request->user());
        } catch (BankingRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            "{$model->number} abandoned. The matches made during it are kept.",
        );
    }

    /**
     * @param  list<BankAccount>  $accounts
     */
    private function selectedAccount(Request $request, array $accounts): ?BankAccount
    {
        if ($accounts === []) {
            return null;
        }

        $requested = $request->string('account')->toString();

        foreach ($accounts as $account) {
            if ($account->id === $requested) {
                return $account;
            }
        }

        return $accounts[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BankReconciliation $reconciliation): array
    {
        return [
            'id' => $reconciliation->id,
            'number' => $reconciliation->number,
            'period_start' => $reconciliation->period_start->toDateString(),
            'period_end' => $reconciliation->period_end->toDateString(),
            'opening_balance' => $reconciliation->opening_balance,
            'closing_balance' => $reconciliation->closing_balance,
            'cleared_balance' => $reconciliation->cleared_balance,
            'difference' => $reconciliation->difference,
            'status' => $reconciliation->status->value,
            'status_label' => $reconciliation->status->label(),
            'notes' => $reconciliation->notes,
            'completed_at' => $reconciliation->completed_at?->toDateTimeString(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(BankAccount $account): array
    {
        return array_values(BankReconciliation::query()
            ->where('bank_account_id', $account->getKey())
            ->where('status', ReconciliationStatus::Completed)
            ->orderByDesc('period_end')
            ->limit(24)
            ->get()
            ->map(fn (BankReconciliation $reconciliation): array => $this->present($reconciliation))
            ->all());
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(Request $request): array
    {
        $user = $request->user();

        return [
            'reconcile' => $user?->can(Permission::BankingReconcile->value) ?? false,
            'import' => $user?->can(Permission::BankingImport->value) ?? false,
        ];
    }
}
