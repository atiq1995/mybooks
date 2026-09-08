<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Models\Account;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The general ledger: every posting to one account, in order, with a running
 * balance.
 *
 * This is the screen an accountant reaches for when a balance looks wrong, so
 * two things matter more than anything else. It must open with an opening
 * balance (a period's movements are meaningless without the figure they start
 * from), and the running balance must be computed in one pass rather than
 * re-summed per row.
 *
 * @see ACCOUNTING_RULES.md §6
 */
final class GeneralLedgerController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::AccountingView->value);

        $organization = $this->tenant->organization();

        $accounts = Account::query()
            ->postable()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'normal_balance']);

        $account = $this->resolveAccount(
            $request,
            array_values($accounts->map(static fn (Account $a): string => $a->id)->all()),
        );

        // Default window: the current financial year to date, which is what
        // somebody opening this screen almost always wants.
        $from = $this->date($request->query('from')) ?? $this->currentYearStart();
        $to = $this->date($request->query('to')) ?? Carbon::now();

        return Inertia::render('Accounting/GeneralLedger', [
            'accounts' => $accounts->map(fn (Account $a): array => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'label' => "{$a->code} — {$a->name}",
            ])->values()->all(),
            'selected' => $account === null ? null : [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type_label' => $account->type->label(),
                'normal_balance' => $account->normal_balance->value,
            ],
            'filters' => [
                'account' => $account?->id,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'ledger' => $account === null
                ? null
                : $this->ledgerFor($account, $from, $to),
            'baseCurrency' => $organization->base_currency,
        ]);
    }

    /**
     * @return array{opening: string, rows: list<array<string, mixed>>, movement_debit: string, movement_credit: string, closing: string}
     */
    private function ledgerFor(Account $account, Carbon $from, Carbon $to): array
    {
        $opening = $this->openingBalance($account, $from);

        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->whereDate('journal_entries.entry_date', '>=', $from->toDateString())
            ->whereDate('journal_entries.entry_date', '<=', $to->toDateString())
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.entry_no')
            ->orderBy('journal_lines.line_no')
            ->select([
                'journal_entries.entry_no',
                'journal_entries.entry_date',
                'journal_entries.source_type',
                'journal_entries.status',
                'journal_entries.memo as entry_memo',
                'journal_lines.debit_base',
                'journal_lines.credit_base',
                'journal_lines.memo as line_memo',
            ])
            ->get();

        $running = BigDecimal::of($opening);
        $debits = BigDecimal::zero();
        $credits = BigDecimal::zero();
        $ledger = [];

        foreach ($rows as $row) {
            /** @var object{entry_no: string, entry_date: string, source_type: string, status: string, entry_memo: ?string, debit_base: string, credit_base: string, line_memo: ?string} $row */
            $debit = BigDecimal::of((string) $row->debit_base);
            $credit = BigDecimal::of((string) $row->credit_base);

            $debits = $debits->plus($debit);
            $credits = $credits->plus($credit);

            // A single pass. The running balance moves in the direction of the
            // account's normal balance, so it reads the way the account does.
            $running = $account->normal_balance === NormalBalance::Debit
                ? $running->plus($debit)->minus($credit)
                : $running->plus($credit)->minus($debit);

            $ledger[] = [
                'entry_no' => $row->entry_no,
                'entry_date' => Carbon::parse($row->entry_date)->toDateString(),
                'source_type' => $row->source_type,
                'status' => $row->status,
                'memo' => $row->line_memo ?? $row->entry_memo,
                'debit' => (string) $debit,
                'credit' => (string) $credit,
                'balance' => (string) $running,
            ];
        }

        return [
            'opening' => $opening,
            'rows' => $ledger,
            'movement_debit' => (string) $debits,
            'movement_credit' => (string) $credits,
            'closing' => (string) $running,
        ];
    }

    /**
     * Everything posted before the window opens, as one figure.
     *
     * Without it the first row's running balance would start from zero and
     * every figure below it would be wrong by the account's whole history.
     */
    private function openingBalance(Account $account, Carbon $from): string
    {
        /** @var object{debits: string|null, credits: string|null}|null $totals */
        $totals = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->whereDate('journal_entries.entry_date', '<', $from->toDateString())
            ->selectRaw('COALESCE(SUM(journal_lines.debit_base), 0) AS debits')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_base), 0) AS credits')
            ->first();

        return $account->normal_balance->signedBalance(
            (string) ($totals->debits ?? '0'),
            (string) ($totals->credits ?? '0'),
        );
    }

    /**
     * @param  list<string>  $allowed
     */
    private function resolveAccount(Request $request, array $allowed): ?Account
    {
        $id = $request->query('account');

        if (! is_string($id) || $id === '') {
            return null;
        }

        // Checked against the postable list rather than fetched blind, so an
        // id from another organisation or a heading cannot be coaxed in.
        if (! in_array($id, $allowed, strict: true)) {
            return null;
        }

        return Account::query()->find($id);
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            // A malformed date in a bookmarked URL falls back to the default
            // window rather than erroring the page.
            return null;
        }
    }

    private function currentYearStart(): Carbon
    {
        $month = $this->tenant->organization()->fiscal_year_start_month;
        $now = Carbon::now();

        $year = (int) $now->format('n') >= $month
            ? (int) $now->format('Y')
            : (int) $now->format('Y') - 1;

        return Carbon::create($year, $month, 1) ?? $now->copy()->startOfYear();
    }
}
