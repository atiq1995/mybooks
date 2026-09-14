<?php

declare(strict_types=1);

namespace App\Domain\Banking\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Banking\Enums\StatementLineStatus;
use App\Domain\Banking\Exceptions\BankingRefused;
use App\Domain\Banking\Models\BankStatementLine;
use App\Domain\Banking\Models\BankTransactionMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Undo a match, or an exclusion.
 *
 * Freely, while the reconciliation is still open — a match is somebody's
 * judgement about what a line refers to, and judgements get revised. Not at
 * all once a reconciliation has been completed: the trigger on the table
 * refuses it outright, and the check here exists only so the refusal arrives
 * as a sentence rather than a database error.
 *
 * Nothing is unposted by this. The journal entry stays exactly where it was;
 * all that changes is our claim about which bank line it corresponds to.
 */
final readonly class UnmatchStatementLine
{
    public function __construct(
        private AuditRecorder $audit,
    ) {}

    public function handle(BankStatementLine $line, ?User $actor = null): BankStatementLine
    {
        if ($line->isLocked()) {
            throw BankingRefused::lineLocked();
        }

        return DB::transaction(function () use ($line, $actor): BankStatementLine {
            $removed = BankTransactionMatch::query()
                ->where('statement_line_id', $line->getKey())
                ->get();

            foreach ($removed as $match) {
                $match->delete();
            }

            $line->forceFill([
                'status' => StatementLineStatus::Unmatched,
                'excluded_reason' => null,
            ])->save();

            $this->audit->record(
                action: 'banking.match_removed',
                subject: $line,
                description: sprintf(
                    'Unmatched "%s" of %s on %s',
                    $line->summary(),
                    $line->amount,
                    $line->transaction_date->toDateString(),
                ),
                old: ['matches' => $removed->count()],
                new: ['status' => StatementLineStatus::Unmatched->value],
                actor: $actor,
            );

            return $line->refresh();
        });
    }

    /**
     * Mark a line as not ours.
     *
     * The legitimate uses are narrow — a feed that duplicated a row, an
     * interest credit on a personal account that shares a feed — and the
     * reason is mandatory because an exclusion is the one move here that
     * makes a difference disappear without explaining it. A database
     * constraint enforces the reason as well, since this is exactly the kind
     * of rule that gets bypassed by the next code path somebody writes.
     */
    public function exclude(BankStatementLine $line, string $reason, ?User $actor = null): BankStatementLine
    {
        if ($line->isLocked()) {
            throw BankingRefused::lineLocked();
        }

        if ($line->status === StatementLineStatus::Matched) {
            throw BankingRefused::lineAlreadySettled($line->summary());
        }

        return DB::transaction(function () use ($line, $reason, $actor): BankStatementLine {
            $line->forceFill([
                'status' => StatementLineStatus::Excluded,
                'excluded_reason' => mb_substr(trim($reason), 0, 255),
            ])->save();

            $this->audit->record(
                action: 'banking.line_excluded',
                subject: $line,
                description: sprintf(
                    'Excluded "%s" of %s — %s',
                    $line->summary(),
                    $line->amount,
                    $reason,
                ),
                new: [
                    'status' => StatementLineStatus::Excluded->value,
                    'reason' => $reason,
                ],
                actor: $actor,
            );

            return $line->refresh();
        });
    }
}
