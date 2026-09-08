<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Actions\ReverseJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Exceptions\UnbalancedJournal;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ReverseJournalRequest;
use App\Http\Requests\Accounting\StoreJournalRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manual journal entries.
 *
 * The only screen in the application where a user writes a journal by hand.
 * Every other module produces its entries from a document, which is why this
 * one is gated behind `accounting.post` and audited on every use.
 *
 * The controller does no accounting: it turns a form into a
 * {@see JournalDraft} and hands it to {@see PostJournalEntry}, which is the
 * only code permitted to write the ledger. A refusal comes back as a
 * validation error against the field that caused it, so the user sees the
 * problem where they made it.
 *
 * @see ACCOUNTING_RULES.md §4, §10
 */
final class JournalController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PostJournalEntry $postJournalEntry,
        private readonly ReverseJournalEntry $reverseJournalEntry,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::AccountingView->value);

        $organization = $this->tenant->organization();

        $entries = JournalEntry::query()
            ->with('fiscalPeriod:id,label')
            ->when(
                is_string($search = $request->query('search')) && $search !== '',
                fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('entry_no', 'ilike', "%{$search}%")
                        ->orWhere('memo', 'ilike', "%{$search}%");
                }),
            )
            ->when(
                is_string($source = $request->query('source')) && $source !== '',
                fn ($query) => $query->where('source_type', $source),
            )
            ->when(
                is_string($from = $request->query('from')) && $from !== '',
                fn ($query) => $query->whereDate('entry_date', '>=', $from),
            )
            ->when(
                is_string($to = $request->query('to')) && $to !== '',
                fn ($query) => $query->whereDate('entry_date', '<=', $to),
            )
            // Newest first, then by number, so entries posted on one date keep
            // the order they were actually made in.
            ->orderByDesc('entry_date')
            ->orderByDesc('entry_no')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Accounting/Journals/Index', [
            'entries' => [
                'data' => collect($entries->items())
                    ->map(fn (JournalEntry $entry): array => $this->summarise($entry))
                    ->all(),
                'links' => $entries->linkCollection()->toArray(),
                'total' => $entries->total(),
                'from' => $entries->firstItem(),
                'to' => $entries->lastItem(),
            ],
            'filters' => [
                'search' => $request->query('search') ?? '',
                'source' => $request->query('source') ?? '',
                'from' => $request->query('from') ?? '',
                'to' => $request->query('to') ?? '',
            ],
            'sources' => JournalEntry::query()
                ->distinct()
                ->orderBy('source_type')
                ->pluck('source_type')
                ->all(),
            'baseCurrency' => $organization->base_currency,
            'can' => [
                'post' => $request->user()?->can(Permission::AccountingPost->value) ?? false,
                'reverse' => $request->user()?->can(Permission::AccountingReverse->value) ?? false,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize(Permission::AccountingPost->value);

        $organization = $this->tenant->organization();

        return Inertia::render('Accounting/Journals/Create', [
            'accounts' => $this->postableAccounts(),
            'baseCurrency' => $organization->base_currency,
            'today' => Carbon::now()->toDateString(),
            'canPostToClosedPeriod' => $request->user()
                ?->can(Permission::AccountingPostToClosedPeriod->value) ?? false,
        ]);
    }

    public function store(StoreJournalRequest $request): RedirectResponse
    {
        $this->authorize(Permission::AccountingPost->value);

        $validated = $request->validated();

        try {
            $draft = $this->draftFrom($validated);
        } catch (\InvalidArgumentException $exception) {
            // A line that is negative or zero. The line constructors refuse
            // it, and the message names which side.
            return back()->withErrors(['lines' => $exception->getMessage()])->withInput();
        }

        try {
            $entry = $this->postJournalEntry->handle(
                draft: $draft,
                actor: $request->user(),
                allowClosedPeriod: ($validated['post_to_closed_period'] ?? false)
                    && ($request->user()?->can(Permission::AccountingPostToClosedPeriod->value) ?? false),
            );
        } catch (UnbalancedJournal $exception) {
            /*
             * The form arithmetic is checked in the browser too, so reaching
             * here means either a hand-crafted request or a rounding
             * difference the client did not see. Either way the ledger is the
             * authority and the message says what it saw.
             */
            return back()->withErrors(['lines' => $exception->getMessage()])->withInput();
        } catch (PostingRefused $exception) {
            return back()->withErrors(['date' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('accounting.journals.show', $entry->entry_no)
            ->with('success', "Journal {$entry->entry_no} posted.");
    }

    public function show(Request $request, string $entryNo): Response
    {
        $this->authorize(Permission::AccountingView->value);

        $entry = JournalEntry::query()
            ->with(['fiscalPeriod:id,label', 'lines.account:id,code,name,type'])
            ->where('entry_no', $entryNo)
            ->firstOrFail();

        $organization = $this->tenant->organization();

        $reversal = $entry->isReversed()
            ? JournalEntry::query()->where('reverses_entry_id', $entry->id)->first()
            : null;

        $reverses = $entry->reverses_entry_id !== null
            ? JournalEntry::query()->whereKey($entry->reverses_entry_id)->first()
            : null;

        return Inertia::render('Accounting/Journals/Show', [
            'entry' => [
                ...$this->summarise($entry),
                'memo' => $entry->memo,
                'exchange_rate' => (string) $entry->exchange_rate,
                'posted_at' => $entry->posted_at->toDayDateTimeString(),
                'lines' => $entry->lines
                    ->sortBy('line_no')
                    ->map(fn (JournalLine $line): array => [
                        'id' => $line->id,
                        'line_no' => $line->line_no,
                        'account_code' => $line->account?->code,
                        'account_name' => $line->account?->name,
                        'debit' => (string) $line->debit,
                        'credit' => (string) $line->credit,
                        'debit_base' => (string) $line->debit_base,
                        'credit_base' => (string) $line->credit_base,
                        'memo' => $line->memo,
                    ])->values()->all(),
                // Both directions of the correction chain, so an auditor can
                // walk it without a second query.
                'reversed_by' => $reversal === null ? null : [
                    'entry_no' => $reversal->entry_no,
                    'entry_date' => $reversal->entry_date->toDateString(),
                ],
                'reverses' => $reverses === null ? null : [
                    'entry_no' => $reverses->entry_no,
                    'entry_date' => $reverses->entry_date->toDateString(),
                ],
            ],
            'baseCurrency' => $organization->base_currency,
            'can' => [
                'reverse' => ($request->user()?->can(Permission::AccountingReverse->value) ?? false)
                    && ! $entry->isReversed()
                    && ! $entry->isReversal(),
            ],
        ]);
    }

    public function reverse(ReverseJournalRequest $request, string $entryNo): RedirectResponse
    {
        $this->authorize(Permission::AccountingReverse->value);

        $entry = JournalEntry::query()->where('entry_no', $entryNo)->firstOrFail();

        $date = $request->string('date')->toString();
        $reason = $request->string('reason')->toString();

        try {
            $reversal = $this->reverseJournalEntry->handle(
                entry: $entry,
                actor: $request->user(),
                // Absent means today, which is deliberate: back-dating a
                // correction changes figures already reported.
                date: $date === '' ? null : Carbon::parse($date),
                reason: $reason === '' ? null : $reason,
            );
        } catch (PostingRefused $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()]);
        }

        return redirect()
            ->route('accounting.journals.show', $reversal->entry_no)
            ->with('success', "{$entry->entry_no} reversed by {$reversal->entry_no}.");
    }

    /**
     * Turn validated form input into a draft.
     *
     * Pure: no database, no writes. The draft is then the only thing the
     * ledger sees, which keeps the posting rules testable without a request.
     *
     * @param  array<string, mixed>  $validated
     */
    private function draftFrom(array $validated): JournalDraft
    {
        /** @var list<array{account_id: string, side: string, amount: string, memo?: string|null}> $rows */
        $rows = is_array($validated['lines'] ?? null) ? array_values($validated['lines']) : [];

        $date = is_string($validated['date'] ?? null) ? $validated['date'] : '';
        $memo = is_string($validated['memo'] ?? null) && $validated['memo'] !== ''
            ? $validated['memo']
            : null;

        $lines = array_map(
            static fn (array $row): JournalLineDraft => $row['side'] === 'debit'
                ? JournalLineDraft::debit(
                    accountId: $row['account_id'],
                    amount: $row['amount'],
                    memo: $row['memo'] ?? null,
                )
                : JournalLineDraft::credit(
                    accountId: $row['account_id'],
                    amount: $row['amount'],
                    memo: $row['memo'] ?? null,
                ),
            $rows,
        );

        $organization = $this->tenant->organization();

        return JournalDraft::inBaseCurrency(
            date: Carbon::parse($date),
            currency: $organization->base_currency,
            lines: array_values($lines),
            // No source id: a manual journal is its own source, and giving it
            // one would make the idempotency index refuse the second manual
            // journal of the day.
            source: ['manual', null, 'issue'],
            memo: $memo,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(JournalEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'entry_no' => $entry->entry_no,
            'entry_date' => $entry->entry_date->toDateString(),
            'period' => $entry->fiscalPeriod?->label,
            'source_type' => $entry->source_type,
            'source_purpose' => $entry->source_purpose,
            'memo' => $entry->memo,
            'currency' => $entry->currency,
            'base_currency' => $entry->base_currency,
            'total' => (string) $entry->total_debit,
            'total_base' => (string) $entry->total_debit_base,
            'status' => $entry->status->value,
            'status_label' => $entry->status->label(),
            'is_reversal' => $entry->isReversal(),
        ];
    }

    /**
     * Accounts a journal line may point at.
     *
     * Headings are excluded, not merely disabled: posting to a heading makes
     * its subtotal meaningless, and offering it invites the mistake.
     *
     * @return list<array<string, mixed>>
     */
    private function postableAccounts(): array
    {
        return array_values(Account::query()
            ->postable()
            ->orderBy('code')
            ->get()
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type_label' => $account->type->label(),
                'normal_balance' => $account->normal_balance->value,
            ])
            ->all());
    }
}
