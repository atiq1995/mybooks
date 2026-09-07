<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Enums\EntryStatus;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalPeriod;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Services\DocumentNumberGenerator;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Money\Context\CustomContext;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE ONLY CODE IN THIS APPLICATION PERMITTED TO WRITE THE LEDGER.
 *
 * Controllers, jobs, listeners and React components never touch
 * `journal_entries` or `journal_lines`. Business documents produce a
 * {@see JournalDraft} from a pure function; this posts it.
 *
 * Everything happens in one transaction: the entry, its lines, the document
 * number, and the audit row commit together or not at all. A half-posted entry
 * would be an unbalanced ledger, which is the one state this system must never
 * reach.
 *
 * The balance invariant is checked here, again by a deferred constraint trigger
 * at COMMIT, and again nightly by `my-books:verify-ledger`. Three independent
 * layers, because any one of them can be defeated by a mistake and the cost of
 * a silently misstated balance is measured in months.
 *
 * @see ACCOUNTING_RULES.md §1, §4
 */
final readonly class PostJournalEntry
{
    public function __construct(
        private TenantContext $tenant,
        private DocumentNumberGenerator $numbers,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  bool  $allowClosedPeriod  caller has already verified the actor
     *                                   holds accounting.post_to_closed_period
     */
    public function handle(
        JournalDraft $draft,
        ?User $actor = null,
        bool $allowClosedPeriod = false,
    ): JournalEntry {
        $organization = $this->tenant->organization();

        // 1. Arithmetic. Cheapest check, so it runs first.
        $draft->assertBalanced();

        // 2. The books are kept in one currency, fixed once anything posts.
        if ($draft->baseCurrency !== $organization->base_currency) {
            throw PostingRefused::currencyMismatch(
                $organization->base_currency,
                $draft->baseCurrency,
            );
        }

        $date = Carbon::parse($draft->date->format('Y-m-d'));

        return DB::transaction(function () use ($draft, $date, $actor, $allowClosedPeriod): JournalEntry {
            // 3. Idempotency, before anything is written.
            $this->assertNotAlreadyPosted($draft);

            // 4. A period that will accept it.
            $period = $this->resolvePeriod($date, $allowClosedPeriod);

            // 5. Accounts that exist, are active, and are not headings.
            $accounts = $this->resolveAccounts($draft);

            $entry = new JournalEntry;

            $entry->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $this->tenant->organization()->getKey(),
                'entry_no' => $this->numbers->next('journal', $date),
                'entry_date' => $date->toDateString(),
                'fiscal_period_id' => $period->getKey(),
                'source_type' => $draft->sourceType(),
                'source_id' => $draft->sourceId(),
                'source_purpose' => $draft->sourcePurpose(),
                'memo' => $draft->memo,
                'currency' => $draft->currency,
                'base_currency' => $draft->baseCurrency,
                'exchange_rate' => $draft->exchangeRate,
                'total_debit' => (string) $draft->totalDebit(),
                'total_credit' => (string) $draft->totalCredit(),
                'total_debit_base' => (string) $draft->totalDebitBase(),
                'total_credit_base' => (string) $draft->totalCreditBase(),
                'status' => EntryStatus::Posted,
                'reverses_entry_id' => $draft->reversesEntryId,
                'posted_by' => $actor?->getKey(),
                'posted_at' => Carbon::now(),
            ])->save();

            $lineNo = 1;

            foreach ($draft->lines as $line) {
                $journalLine = new JournalLine;

                $journalLine->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $entry->organization_id,
                    'journal_entry_id' => $entry->getKey(),
                    'line_no' => $lineNo++,
                    'account_id' => $line->accountId,
                    'debit' => (string) $line->debitValue(),
                    'credit' => (string) $line->creditValue(),
                    'debit_base' => (string) $line->debitBaseValue($draft->exchangeRate),
                    'credit_base' => (string) $line->creditBaseValue($draft->exchangeRate),
                    'memo' => $line->memo,
                    'contact_id' => $line->contactId,
                    'item_id' => $line->itemId,
                    'project_id' => $line->projectId,
                    'warehouse_id' => $line->warehouseId,
                    'tax_id' => $line->taxId,
                ])->save();
            }

            $this->audit->record(
                action: 'journal.posted',
                subject: $entry,
                description: sprintf(
                    'Posted %s from %s%s',
                    $entry->entry_no,
                    $draft->sourceType(),
                    $period->status->acceptsPostings() ? '' : ' into a CLOSED period',
                ),
                new: [
                    'entry_no' => $entry->entry_no,
                    'entry_date' => $entry->entry_date->toDateString(),
                    'source_type' => $draft->sourceType(),
                    'source_purpose' => $draft->sourcePurpose(),
                    'lines' => count($draft->lines),
                    'accounts' => array_map(
                        static fn (Account $account): string => $account->code,
                        array_values($accounts),
                    ),
                ],
                amount: Money::of(
                    (string) $draft->totalDebit(),
                    $draft->currency,
                    new CustomContext(4),
                ),
                actor: $actor,
            );

            return $entry;
        });
    }

    /**
     * A source may post several times for DIFFERENT purposes — an invoice
     * issues, then settles — but never twice for the same one. A unique index
     * enforces it too; this exists to give a readable error instead of a
     * constraint violation.
     */
    private function assertNotAlreadyPosted(JournalDraft $draft): void
    {
        if ($draft->sourceId() === null) {
            return;
        }

        $exists = JournalEntry::query()
            ->where('source_type', $draft->sourceType())
            ->where('source_id', $draft->sourceId())
            ->where('source_purpose', $draft->sourcePurpose())
            ->exists();

        if ($exists) {
            throw PostingRefused::alreadyPosted(
                $draft->sourceType(),
                $draft->sourceId(),
                $draft->sourcePurpose(),
            );
        }
    }

    private function resolvePeriod(Carbon $date, bool $allowClosedPeriod): FiscalPeriod
    {
        $period = FiscalPeriod::query()
            ->where('starts_on', '<=', $date->toDateString())
            ->where('ends_on', '>=', $date->toDateString())
            ->first();

        if ($period === null) {
            throw PostingRefused::noPeriodForDate($date->toDateString());
        }

        if ($period->acceptsPostings()) {
            return $period;
        }

        // Locked means filed. No permission reopens it.
        if (! $period->status->acceptsOverride()) {
            throw PostingRefused::periodLocked($period->label);
        }

        if (! $allowClosedPeriod) {
            throw PostingRefused::periodClosed($period->label, $date->toDateString());
        }

        return $period;
    }

    /**
     * @return array<string, Account>
     */
    private function resolveAccounts(JournalDraft $draft): array
    {
        $ids = array_values(array_unique(
            array_map(static fn ($line): string => $line->accountId, $draft->lines),
        ));

        /** @var array<string, Account> $accounts */
        $accounts = Account::query()->whereKey($ids)->get()->keyBy('id')->all();

        foreach ($ids as $id) {
            $account = $accounts[$id] ?? null;

            // Absent means it belongs to another organisation, or does not
            // exist — the tenant scope makes those indistinguishable, which is
            // the point.
            if ($account === null) {
                throw PostingRefused::accountNotFound($id);
            }

            if ($account->is_header) {
                throw PostingRefused::accountIsHeader($account->code, $account->name);
            }

            if (! $account->acceptsPostings()) {
                throw PostingRefused::accountInactive($account->code, $account->name);
            }
        }

        return $accounts;
    }

    /**
     * The permission a caller needs before invoking this.
     *
     * Exposed so controllers and Actions authorise consistently rather than
     * each spelling the string themselves.
     */
    public static function permission(): Permission
    {
        return Permission::AccountingPost;
    }
}
