<?php

declare(strict_types=1);

namespace App\Http\Controllers\Banking;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Banking\Actions\RecordBankTransfer;
use App\Domain\Banking\Exceptions\BankingRefused;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankTransfer;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Transfers between the organisation's own accounts.
 *
 * The one banking screen that posts, so it is also the one that takes
 * `accounting.post` on top of `banking.transfer`: recording a transfer writes
 * a journal entry, and the permission to move money between accounts is not
 * the permission to write in the ledger.
 */
final class BankTransferController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly RecordBankTransfer $recordTransfer,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::BankingView->value);

        $organization = $this->tenant->organization();

        $transfers = BankTransfer::query()
            ->with(['fromAccount:id,code,name', 'toAccount:id,code,name', 'journalEntry:id,entry_no'])
            ->orderByDesc('transfer_date')
            ->orderByDesc('number')
            ->paginate(50)
            ->withQueryString();

        $accounts = BankAccount::query()
            ->usable()
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        return Inertia::render('Banking/Transfers', [
            'transfers' => [
                'data' => array_values(array_map(
                    static fn (BankTransfer $transfer): array => [
                        'id' => $transfer->id,
                        'number' => $transfer->number,
                        'date' => $transfer->transfer_date->toDateString(),
                        'from' => $transfer->fromAccount?->name,
                        'to' => $transfer->toAccount?->name,
                        'currency' => $transfer->currency,
                        'amount' => $transfer->amount,
                        'destination_currency' => $transfer->destination_currency,
                        'amount_received' => $transfer->amount_received,
                        'is_cross_currency' => $transfer->isCrossCurrency(),
                        'reference' => $transfer->reference,
                        'entry_no' => $transfer->journalEntry?->entry_no,
                        'is_voided' => $transfer->isVoided(),
                    ],
                    $transfers->items(),
                )),
                'links' => $transfers->linkCollection()->toArray(),
                'total' => $transfers->total(),
                'from' => $transfers->firstItem(),
                'to' => $transfers->lastItem(),
            ],
            'accounts' => array_values($accounts
                ->map(static fn (BankAccount $account): array => [
                    'value' => $account->id,
                    'label' => $account->label(),
                    'currency' => $account->currency,
                ])
                ->all()),
            'today' => Carbon::now()->toDateString(),
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permission::BankingTransfer->value);

        /*
         * Recording a transfer posts. The permission to move money between
         * accounts is not the permission to write in the ledger, and treating
         * them as one would let a role defined as "cannot post" post.
         */
        $this->authorize(Permission::AccountingPost->value);

        $request->validate([
            'from_account_id' => ['required', 'uuid', 'different:to_account_id'],
            'to_account_id' => ['required', 'uuid'],
            'transfer_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'amount_received' => ['nullable', 'numeric', 'gt:0'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'destination_exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $from = BankAccount::query()->findOrFail($request->string('from_account_id')->toString());
        $to = BankAccount::query()->findOrFail($request->string('to_account_id')->toString());

        try {
            $transfer = $this->recordTransfer->handle(
                from: $from,
                to: $to,
                amount: $request->string('amount')->toString(),
                transferDate: Carbon::parse($request->string('transfer_date')->toString()),
                amountReceived: $request->string('amount_received')->toString() ?: null,
                exchangeRate: $request->string('exchange_rate')->toString() ?: '1',
                destinationExchangeRate: $request->string('destination_exchange_rate')->toString() ?: '1',
                reference: $request->string('reference')->toString() ?: null,
                notes: $request->string('notes')->toString() ?: null,
                actor: $request->user(),
            );
        } catch (BankingRefused|PostingRefused|\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return back()->with('success', "Transfer {$transfer->number} recorded.");
    }

    public function void(Request $request, string $transfer): RedirectResponse
    {
        $this->authorize(Permission::BankingTransfer->value);

        // Voiding posts a reversal, so it needs the permission to reverse —
        // the same rule the sales and purchase documents follow.
        $this->authorize(Permission::AccountingReverse->value);

        $model = BankTransfer::query()->findOrFail($transfer);

        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $voided = $this->recordTransfer->void(
                transfer: $model,
                actor: $request->user(),
                reason: $request->string('reason')->toString() ?: null,
            );
        } catch (BankingRefused|PostingRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Transfer {$voided->number} voided by reversal.");
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(Request $request): array
    {
        $user = $request->user();

        $mayTransfer = $user?->can(Permission::BankingTransfer->value) ?? false;
        $mayPost = $user?->can(Permission::AccountingPost->value) ?? false;

        return [
            // The button follows the same rule the route enforces, so a
            // control that would only ever be refused is absent instead.
            'create' => $mayTransfer && $mayPost,
            'void' => $mayTransfer && ($user?->can(Permission::AccountingReverse->value) ?? false),
        ];
    }
}
