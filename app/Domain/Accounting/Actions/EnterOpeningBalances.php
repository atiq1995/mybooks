<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Bring balances forward from a previous system.
 *
 * §4.13, and the section is shorter than the decisions it implies:
 *
 *   Dr each asset              its balance
 *      Cr each liability       its balance
 *   Dr/Cr Opening Balance Equity   whatever balances
 *
 * OPENING BALANCE EQUITY IS A PLUG, and it is supposed to be. Its whole
 * purpose is to hold the difference while a migration is half done, so that
 * every intermediate state still balances and the ledger never has to accept
 * an unbalanced entry "just for now". When the migration is complete it is
 * zero, and a non-zero balance afterwards is the single most useful signal
 * that something was missed — which is why the screen reports it rather than
 * hiding it.
 *
 * ONE ENTRY, not one per account. The opening position is a single event: it
 * happened on one date and it is either right or wrong as a whole. A hundred
 * separate entries would each balance against equity individually, which
 * makes the equity account unreadable and loses the fact that they are one
 * statement of one position.
 *
 * RECEIVABLES AND PAYABLES ARE NOT ENTERED HERE. A lump in the AR control
 * account cannot be aged, chased, or reconciled to a customer, so those come
 * across as individual documents — see {@see EnterOpeningDocument}.
 * This action refuses a line pointing at either control account, because
 * accepting one would double-count against the documents.
 *
 * @see ACCOUNTING_RULES.md §4.13
 */
final readonly class EnterOpeningBalances
{
    public function __construct(
        private TenantContext $tenant,
        private PostJournalEntry $postJournalEntry,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  list<array{account_id: string, debit?: string|null, credit?: string|null}>  $balances
     */
    public function handle(
        array $balances,
        Carbon $asOf,
        ?User $actor = null,
        bool $allowClosedPeriod = false,
    ): JournalEntry {
        if ($balances === []) {
            throw new \InvalidArgumentException(
                'Enter at least one balance. An opening position with nothing in it is '.
                'not a migration, it is a no-op.'
            );
        }

        $organization = $this->tenant->organization();
        $equityId = $this->systemAccountId(SystemAccount::OpeningBalanceEquity);

        $controlAccounts = [
            $this->systemAccountId(SystemAccount::AccountsReceivable),
            $this->systemAccountId(SystemAccount::AccountsPayable),
        ];

        $lines = [];
        $net = BigDecimal::zero();

        foreach ($balances as $balance) {
            $accountId = $balance['account_id'];

            /*
             * The control accounts are refused, not silently dropped.
             *
             * Somebody entering a total receivable here has a reasonable
             * mental model — it is a balance, after all — and the refusal has
             * to explain why the answer is "as invoices" rather than just
             * saying no.
             */
            if (in_array($accountId, $controlAccounts, strict: true)) {
                throw PostingRefused::openingControlAccount(
                    Account::query()->findOrFail($accountId)->name,
                );
            }

            $debit = self::amount($balance['debit'] ?? null);
            $credit = self::amount($balance['credit'] ?? null);

            if ($debit->isPositive() && $credit->isPositive()) {
                throw new \InvalidArgumentException(
                    'A balance is a debit or a credit, not both. An account with '.
                    'movement on each side has one net balance, and that is what comes '.
                    'across.'
                );
            }

            // An account entered as zero is not an error — it is somebody
            // working down a list — but it carries no information either.
            if ($debit->isZero() && $credit->isZero()) {
                continue;
            }

            $lines[] = $debit->isPositive()
                ? JournalLineDraft::debit(
                    accountId: $accountId,
                    amount: (string) $debit->toScale(4, RoundingMode::HalfUp),
                    memo: 'Opening balance',
                )
                : JournalLineDraft::credit(
                    accountId: $accountId,
                    amount: (string) $credit->toScale(4, RoundingMode::HalfUp),
                    memo: 'Opening balance',
                );

            $net = $net->plus($debit)->minus($credit);
        }

        if ($lines === []) {
            throw new \InvalidArgumentException(
                'Every balance entered was zero, so there is nothing to bring forward.'
            );
        }

        /*
         * The plug.
         *
         * More debits than credits means the assets brought across exceed the
         * liabilities, and the difference is what the business was worth on
         * that date — a CREDIT to equity. The reverse happens too, and is not
         * an error: a business migrating with more liabilities than assets has
         * negative equity, and saying so is the point.
         */
        if (! $net->isZero()) {
            $lines[] = $net->isPositive()
                ? JournalLineDraft::credit(
                    accountId: $equityId,
                    amount: (string) $net->toScale(4, RoundingMode::HalfUp),
                    memo: 'Opening balance equity',
                )
                : JournalLineDraft::debit(
                    accountId: $equityId,
                    amount: (string) $net->abs()->toScale(4, RoundingMode::HalfUp),
                    memo: 'Opening balance equity',
                );
        }

        return DB::transaction(function () use (
            $lines,
            $asOf,
            $actor,
            $organization,
            $allowClosedPeriod,
            $net,
        ): JournalEntry {
            $entry = $this->postJournalEntry->handle(
                draft: new JournalDraft(
                    date: $asOf,
                    currency: $organization->base_currency,
                    baseCurrency: $organization->base_currency,
                    exchangeRate: '1',
                    lines: $lines,
                    /*
                     * A deterministic source, so entering the opening
                     * position twice is refused by the idempotency index
                     * rather than doubling every balance. Re-running a
                     * migration is a thing people do.
                     */
                    source: ['opening_balance', $this->sourceId($asOf), 'issue'],
                    memo: 'Opening balances as at '.$asOf->toDateString(),
                ),
                actor: $actor,
                allowClosedPeriod: $allowClosedPeriod,
            );

            $this->audit->record(
                action: 'accounting.opening_balances_entered',
                subject: $entry,
                description: sprintf(
                    'Entered opening balances as at %s — %d accounts, %s to equity',
                    $asOf->toDateString(),
                    count($lines) - ($net->isZero() ? 0 : 1),
                    (string) $net->abs()->toScale(2),
                ),
                new: [
                    'as_of' => $asOf->toDateString(),
                    'accounts' => count($lines) - ($net->isZero() ? 0 : 1),
                    'equity' => (string) $net->abs()->toScale(4),
                    'entry' => $entry->entry_no,
                ],
                actor: $actor,
            );

            return $entry;
        });
    }

    /**
     * What remains in opening balance equity.
     *
     * Zero when the migration is complete, and the single most useful number
     * on the screen: any other figure means an account, an invoice or a bill
     * was missed, and it says by how much.
     */
    public function equityRemaining(?Carbon $asOf = null): string
    {
        $account = Account::query()
            ->where('system_role', SystemAccount::OpeningBalanceEquity->value)
            ->first();

        return $account === null ? '0.0000' : $account->balance($asOf);
    }

    /**
     * A stable id for the opening position at a date.
     *
     * Derived rather than random, so a retry hits the ledger's idempotency
     * index instead of doubling every balance. The date is part of it because
     * a correction entered at a different date is a different event.
     */
    private function sourceId(Carbon $asOf): string
    {
        return (string) Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'my-books:opening-balance:'
                .$this->tenant->organization()->id
                .':'.$asOf->toDateString(),
        );
    }

    private static function amount(mixed $value): BigDecimal
    {
        if (! is_string($value) || trim($value) === '') {
            return BigDecimal::zero();
        }

        $amount = BigDecimal::of(trim($value));

        if ($amount->isNegative()) {
            throw new \InvalidArgumentException(
                'Enter a balance as a positive figure in the debit or the credit column. '.
                'A negative debit is a credit, and writing it as one is what keeps the '.
                'trial balance readable.'
            );
        }

        return $amount;
    }

    private function systemAccountId(SystemAccount $role): string
    {
        $id = Account::query()->where('system_role', $role->value)->value('id');

        if (! is_string($id)) {
            throw PostingRefused::missingSystemAccount($role->value);
        }

        return $id;
    }
}
