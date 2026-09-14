<?php

declare(strict_types=1);

namespace App\Http\Controllers\Banking;

use App\Domain\Access\Enums\Permission;
use App\Domain\Banking\Actions\ConfirmStatementMatch;
use App\Domain\Banking\Actions\ImportBankStatement;
use App\Domain\Banking\Actions\UnmatchStatementLine;
use App\Domain\Banking\Data\MatchSuggestion;
use App\Domain\Banking\Enums\StatementFormat;
use App\Domain\Banking\Enums\StatementLineStatus;
use App\Domain\Banking\Exceptions\BankingRefused;
use App\Domain\Banking\Exceptions\StatementUnreadable;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankStatementImport;
use App\Domain\Banking\Models\BankStatementLine;
use App\Domain\Banking\Models\BankTransactionMatch;
use App\Domain\Banking\Services\MatchSuggester;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Imported statement lines, and what a person decides they mean.
 *
 * The separation of duties this screen encodes is the whole of §8. Importing
 * needs `banking.import`, which a bookkeeper has: getting the bank's data into
 * the system changes nothing. Matching, unmatching and excluding need
 * `banking.reconcile`, which a bookkeeper does not have — because those are
 * the judgements the reconciliation rests on.
 *
 * Neither posts. Nothing on this screen can produce a journal line.
 */
final class BankTransactionController extends Controller
{
    /** Lines per page. A statement month is rarely more than this. */
    private const PER_PAGE = 100;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ImportBankStatement $importStatement,
        private readonly ConfirmStatementMatch $confirmMatch,
        private readonly UnmatchStatementLine $unmatchLine,
        private readonly MatchSuggester $suggester,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::BankingView->value);

        $organization = $this->tenant->organization();

        $accounts = BankAccount::query()
            ->usable()
            ->with('account:id,code,name')
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        $selected = $this->selectedAccount($request, array_values($accounts->all()));

        if ($selected === null) {
            return Inertia::render('Banking/Transactions', [
                'accounts' => [],
                'account' => null,
                'lines' => ['data' => [], 'links' => [], 'total' => 0],
                'filters' => ['status' => '', 'search' => ''],
                'suggestions' => [],
                'selectedLine' => null,
                'imports' => [],
                'summary' => ['unmatched' => 0, 'matched' => 0, 'excluded' => 0],
                'formats' => $this->formatOptions(),
                'baseCurrency' => $organization->base_currency,
                'can' => $this->abilities($request),
            ]);
        }

        $status = $request->string('status')->toString();
        $search = trim($request->string('search')->toString());

        $lines = BankStatementLine::query()
            ->with(['matches.journalLine.journalEntry:id,entry_no,entry_date,source_type'])
            ->where('bank_account_id', $selected->getKey())
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(
                fn ($inner) => $inner
                    ->where('description', 'ilike', "%{$search}%")
                    ->orWhere('reference', 'ilike', "%{$search}%")
                    ->orWhere('payee', 'ilike', "%{$search}%"),
            ))
            ->orderByDesc('transaction_date')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /*
         * Suggestions are computed for ONE line — whichever is open — rather
         * than for every row. A suggestion list per row would be a query per
         * row, and the screen would slow down exactly as a statement grew.
         */
        $selectedLine = $this->selectedLine($request, $selected);

        return Inertia::render('Banking/Transactions', [
            'accounts' => $this->accountOptions(array_values($accounts->all())),
            'account' => [
                'id' => $selected->id,
                'name' => $selected->name,
                'label' => $selected->label(),
                'currency' => $selected->currency,
                'kind' => $selected->kind->value,
                'supports_statements' => $selected->kind->hasStatements(),
            ],
            'lines' => [
                'data' => array_values(array_map(
                    fn (BankStatementLine $line): array => $this->present($line),
                    $lines->items(),
                )),
                'links' => $lines->linkCollection()->toArray(),
                'total' => $lines->total(),
                'from' => $lines->firstItem(),
                'to' => $lines->lastItem(),
            ],
            'filters' => ['status' => $status, 'search' => $search],
            'selectedLine' => $selectedLine === null ? null : $this->present($selectedLine),
            'suggestions' => $selectedLine === null
                ? []
                : array_map(
                    static fn (MatchSuggestion $suggestion): array => [
                        'journal_line_id' => $suggestion->journalLineId,
                        'entry_no' => $suggestion->entryNo,
                        'entry_date' => $suggestion->entryDate->toDateString(),
                        'amount' => $suggestion->amount,
                        'memo' => $suggestion->memo,
                        'source_type' => $suggestion->sourceType,
                        'contact_name' => $suggestion->contactName,
                        'confidence' => $suggestion->confidence,
                        'reasons' => $suggestion->reasons,
                        'is_strong' => $suggestion->isStrong(),
                    ],
                    $this->suggester->for($selectedLine),
                ),
            'imports' => $this->recentImports($selected),
            'summary' => $this->summary($selected),
            'formats' => $this->formatOptions(),
            'baseCurrency' => $organization->base_currency,
            'can' => $this->abilities($request),
        ]);
    }

    public function import(Request $request, string $bankAccount): RedirectResponse
    {
        $this->authorize(Permission::BankingImport->value);

        $account = BankAccount::query()->findOrFail($bankAccount);

        $request->validate([
            /*
             * 10 MB. A statement is text; anything larger is a different kind
             * of file that happens to have the right extension.
             */
            'statement' => ['required', 'file', 'max:10240'],
            'format' => ['nullable', Rule::enum(StatementFormat::class)],
        ]);

        $file = $request->file('statement');

        if (! $file instanceof UploadedFile) {
            return back()->withErrors(['statement' => 'No file arrived. Try again.']);
        }

        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            return back()->withErrors(['statement' => 'The file could not be read.']);
        }

        $format = $request->string('format')->toString();

        try {
            $import = $this->importStatement->handle(
                bankAccount: $account,
                contents: $contents,
                filename: $file->getClientOriginalName(),
                format: $format === '' ? null : StatementFormat::from($format),
                actor: $request->user(),
            );
        } catch (StatementUnreadable|BankingRefused $exception) {
            return back()->withErrors(['statement' => $exception->getMessage()]);
        }

        return back()->with('success', sprintf(
            '%s read: %d new %s, %d already present.%s',
            $import->filename,
            $import->rows_imported,
            $import->rows_imported === 1 ? 'transaction' : 'transactions',
            $import->rows_duplicate,
            // Said plainly, because it is the thing people most often assume
            // an import has done.
            ' Nothing has been posted — match each line to record it.',
        ));
    }

    public function match(Request $request, string $line): RedirectResponse
    {
        $this->authorize(Permission::BankingReconcile->value);

        $statementLine = BankStatementLine::query()->findOrFail($line);

        $request->validate([
            'journal_line_id' => ['required', 'uuid'],
            'origin' => ['nullable', 'in:manual,suggested'],
            'confidence' => ['nullable', 'integer', 'between:0,100'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->confirmMatch->handle(
                line: $statementLine,
                journalLineId: $request->string('journal_line_id')->toString(),
                actor: $request->user(),
                origin: $request->string('origin')->toString() === 'suggested' ? 'suggested' : 'manual',
                confidence: $request->has('confidence') ? $request->integer('confidence') : null,
                note: $request->string('note')->toString() ?: null,
            );
        } catch (BankingRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Matched. Nothing was posted — both sides already existed.');
    }

    public function unmatch(Request $request, string $line): RedirectResponse
    {
        $this->authorize(Permission::BankingReconcile->value);

        $statementLine = BankStatementLine::query()->findOrFail($line);

        try {
            $this->unmatchLine->handle($statementLine, $request->user());
        } catch (BankingRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Unmatched.');
    }

    public function exclude(Request $request, string $line): RedirectResponse
    {
        $this->authorize(Permission::BankingReconcile->value);

        $statementLine = BankStatementLine::query()->findOrFail($line);

        $request->validate([
            // Mandatory, because an exclusion is the one move here that makes
            // a difference disappear without explaining it.
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        try {
            $this->unmatchLine->exclude(
                $statementLine,
                $request->string('reason')->toString(),
                $request->user(),
            );
        } catch (BankingRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Excluded, with the reason recorded.');
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

    private function selectedLine(Request $request, BankAccount $account): ?BankStatementLine
    {
        $requested = $request->string('line')->toString();

        if ($requested === '') {
            return null;
        }

        return BankStatementLine::query()
            ->with('matches')
            ->where('bank_account_id', $account->getKey())
            ->find($requested);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BankStatementLine $line): array
    {
        return [
            'id' => $line->id,
            'date' => $line->transaction_date->toDateString(),
            'description' => $line->description,
            'summary' => $line->summary(),
            'reference' => $line->reference,
            'payee' => $line->payee,
            'amount' => $line->amount,
            'is_inflow' => $line->isInflow(),
            'statement_balance' => $line->statement_balance,
            'status' => $line->status->value,
            'status_label' => $line->status->label(),
            'excluded_reason' => $line->excluded_reason,
            'is_locked' => $line->isLocked(),
            'matches' => array_values($line->matches
                ->map(static function (BankTransactionMatch $match): array {
                    $entry = $match->journalLine?->journalEntry;

                    return [
                        'id' => $match->id,
                        'amount' => $match->amount,
                        'entry_no' => $entry?->entry_no,
                        'entry_date' => $entry?->entry_date->toDateString(),
                        'source_type' => $entry?->source_type,
                        'origin' => $match->origin,
                    ];
                })
                ->all()),
        ];
    }

    /**
     * @param  list<BankAccount>  $accounts
     * @return list<array{value: string, label: string, currency: string, supports_statements: bool}>
     */
    private function accountOptions(array $accounts): array
    {
        return array_values(array_map(
            static fn (BankAccount $account): array => [
                'value' => $account->id,
                'label' => $account->label(),
                'currency' => $account->currency,
                'supports_statements' => $account->kind->hasStatements(),
            ],
            $accounts,
        ));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function formatOptions(): array
    {
        return array_values(array_map(
            static fn (StatementFormat $format): array => [
                'value' => $format->value,
                'label' => $format->label(),
            ],
            StatementFormat::cases(),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentImports(BankAccount $account): array
    {
        return array_values(BankStatementImport::query()
            ->where('bank_account_id', $account->getKey())
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(static fn (BankStatementImport $import): array => [
                'id' => $import->id,
                'filename' => $import->filename,
                'format' => $import->format->label(),
                'imported' => $import->rows_imported,
                'duplicate' => $import->rows_duplicate,
                'rejected' => $import->rowsRejected(),
                'period_start' => $import->statement_start?->toDateString(),
                'period_end' => $import->statement_end?->toDateString(),
                'at' => $import->created_at?->toDateTimeString(),
            ])
            ->all());
    }

    /**
     * @return array{unmatched: int, matched: int, excluded: int}
     */
    private function summary(BankAccount $account): array
    {
        /** @var object{unmatched: int, matched: int, excluded: int} $counts */
        $counts = BankStatementLine::query()
            ->where('bank_account_id', $account->getKey())
            ->selectRaw(
                'COUNT(*) FILTER (WHERE status = ?) AS unmatched, '.
                'COUNT(*) FILTER (WHERE status = ?) AS matched, '.
                'COUNT(*) FILTER (WHERE status = ?) AS excluded',
                [
                    StatementLineStatus::Unmatched->value,
                    StatementLineStatus::Matched->value,
                    StatementLineStatus::Excluded->value,
                ],
            )
            ->firstOrFail();

        return [
            'unmatched' => $counts->unmatched,
            'matched' => $counts->matched,
            'excluded' => $counts->excluded,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(Request $request): array
    {
        $user = $request->user();

        return [
            'import' => $user?->can(Permission::BankingImport->value) ?? false,
            'reconcile' => $user?->can(Permission::BankingReconcile->value) ?? false,
            'manage' => $user?->can(Permission::BankingManageAccounts->value) ?? false,
        ];
    }
}
