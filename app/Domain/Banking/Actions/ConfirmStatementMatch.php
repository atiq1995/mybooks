<?php

declare(strict_types=1);

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Banking\Enums\StatementLineStatus;
use App\Domain\Banking\Exceptions\BankingRefused;
use App\Domain\Banking\Models\BankStatementLine;
use App\Domain\Banking\Models\BankTransactionMatch;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A person says: this statement line is that journal line.
 *
 * The whole of Phase 6's control sits in this one action. §8 requires the
 * confirmation to be explicit, permissioned and audited, and all three are
 * here: nothing calls this except a request carrying `banking.reconcile`, the
 * person is recorded on the row, and an audit entry names both sides.
 *
 * **It posts nothing.** Both sides existed already — the journal entry was
 * posted when the payment was recorded, and the statement line came from the
 * bank. A match is an assertion about two things that are already true, which
 * is exactly why it is safe for somebody without posting permission to make
 * one.
 *
 * Four refusals, each protecting a different way a reconciliation can be
 * forced to zero while being wrong:
 *
 *   - the entry must be ON this bank account;
 *   - it must move money the same way the statement says;
 *   - it must not already have cleared something else;
 *   - the matches on a line must never exceed what the line is worth.
 */
final readonly class ConfirmStatementMatch
{
    public function __construct(
        private TenantContext $tenant,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        BankStatementLine $line,
        string $journalLineId,
        ?User $actor = null,
        string $origin = 'manual',
        ?int $confidence = null,
        ?string $note = null,
    ): BankTransactionMatch {
        if ($line->isLocked()) {
            throw BankingRefused::lineLocked();
        }

        if ($line->status === StatementLineStatus::Excluded) {
            throw BankingRefused::lineAlreadySettled($line->summary());
        }

        $organization = $this->tenant->organization();

        return DB::transaction(function () use (
            $line,
            $journalLineId,
            $actor,
            $origin,
            $confidence,
            $note,
            $organization,
        ): BankTransactionMatch {
            /** @var JournalLine $journalLine */
            $journalLine = JournalLine::query()
                ->with('journalEntry')
                ->findOrFail($journalLineId);

            $entry = $journalLine->journalEntry;

            if ($entry === null) {
                throw new \LogicException(
                    "Journal line {$journalLine->id} has no entry, which the ledger forbids."
                );
            }

            $bankAccount = $line->bankAccountOrFail();

            if ($journalLine->account_id !== $bankAccount->account_id) {
                throw BankingRefused::journalLineNotOnAccount();
            }

            $signed = BigDecimal::of($journalLine->debit)
                ->minus(BigDecimal::of($journalLine->credit))
                ->toScale(4, RoundingMode::HalfUp);

            $lineAmount = BigDecimal::of($line->amount)->toScale(4, RoundingMode::HalfUp);

            /*
             * Direction before amount, because the message is more useful.
             * "That entry moves money the other way" is something a person
             * can act on; "over-allocated by 25,000" when the sign is wrong
             * sends them looking for an arithmetic error that is not there.
             */
            if ($signed->isNegative() !== $lineAmount->isNegative()) {
                throw BankingRefused::matchWrongDirection();
            }

            $alreadyMatched = BankTransactionMatch::query()
                ->where('statement_line_id', $line->getKey())
                ->sum('amount');

            $matched = BigDecimal::of((string) $alreadyMatched)->toScale(4, RoundingMode::HalfUp);
            $remaining = $lineAmount->minus($matched);

            if ($signed->abs()->isGreaterThan($remaining->abs())) {
                throw BankingRefused::matchOverAllocated((string) $remaining);
            }

            $taken = BankTransactionMatch::query()
                ->where('journal_line_id', $journalLine->getKey())
                ->exists();

            if ($taken) {
                throw BankingRefused::journalLineAlreadyMatched(
                    $entry->entry_no,
                );
            }

            $match = new BankTransactionMatch;

            $match->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organization->getKey(),
                'bank_account_id' => $bankAccount->getKey(),
                'statement_line_id' => $line->getKey(),
                'journal_line_id' => $journalLine->getKey(),
                'amount' => (string) $signed,
                'origin' => $origin === 'suggested' ? 'suggested' : 'manual',
                'confidence' => $confidence,
                'note' => $note,
                'matched_by' => $actor?->getKey(),
                'matched_at' => Carbon::now(),
            ])->save();

            /*
             * Fully matched only when the matches add up to the line. A
             * deposit covering two invoices stays unmatched until the second
             * one is confirmed, which is the honest state: it is partly
             * explained.
             */
            if ($matched->plus($signed)->isEqualTo($lineAmount)) {
                $line->forceFill(['status' => StatementLineStatus::Matched])->save();
            }

            $this->audit->record(
                action: 'banking.match_confirmed',
                subject: $match,
                description: sprintf(
                    'Matched "%s" of %s on %s to entry %s',
                    $line->summary(),
                    $line->amount,
                    $line->transaction_date->toDateString(),
                    $entry->entry_no,
                ),
                new: [
                    'statement_line' => $line->getKey(),
                    'entry_no' => $entry->entry_no,
                    'amount' => (string) $signed,
                    'origin' => $match->origin,
                    'confidence' => $confidence,
                ],
                actor: $actor,
            );

            return $match->refresh();
        });
    }
}
