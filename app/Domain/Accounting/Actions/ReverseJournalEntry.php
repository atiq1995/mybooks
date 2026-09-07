<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use App\Domain\Accounting\Enums\EntryStatus;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reverses a posted entry by posting its mirror image.
 *
 * This is how the ledger corrects itself. The original is never edited and
 * never deleted — both entries stay visible for ever, which is precisely what
 * an auditor needs: the mistake, the correction, and the order they happened.
 *
 * The reversal is dated in an OPEN period rather than on the original's date.
 * Back-dating a correction into a closed period would change figures that have
 * already been reported.
 *
 * @see ACCOUNTING_RULES.md §4.15
 */
final readonly class ReverseJournalEntry
{
    public function __construct(
        private PostJournalEntry $postJournalEntry,
    ) {}

    public function handle(
        JournalEntry $entry,
        ?User $actor = null,
        ?Carbon $date = null,
        ?string $reason = null,
    ): JournalEntry {
        if ($entry->isReversed()) {
            throw PostingRefused::alreadyReversed($entry->entry_no);
        }

        /*
         * Reversing a reversal would restore the original error, and the
         * resulting chain is impossible to read. A fresh correcting entry is
         * always the right move instead.
         */
        if ($entry->isReversal()) {
            throw PostingRefused::cannotReverseAReversal($entry->entry_no);
        }

        $date ??= Carbon::now();

        return DB::transaction(function () use ($entry, $actor, $date, $reason): JournalEntry {
            $entryId = $entry->id;
            $lines = $entry->lines()->get();

            $draft = new JournalDraft(
                date: $date,
                currency: $entry->currency,
                baseCurrency: $entry->base_currency,
                // The ORIGINAL rate, not today's. A reversal must undo the
                // entry exactly; converting at a new rate would leave an
                // unintended FX difference behind.
                exchangeRate: $entry->exchange_rate,
                lines: array_values($lines
                    ->map(fn (JournalLine $line): JournalLineDraft => $this->mirror($line))
                    ->all()),
                source: ['reversal', $entryId, 'reverse'],
                memo: $reason ?? "Reversal of {$entry->entry_no}",
                reversesEntryId: $entryId,
            );

            $reversal = $this->postJournalEntry->handle($draft, $actor);

            // Marking the original reversed is the one edit the append-only
            // trigger permits.
            $entry->forceFill(['status' => EntryStatus::Reversed])->save();

            return $reversal;
        });
    }

    /**
     * Swap the sides, preserving the base amounts exactly.
     *
     * The base override matters: recomputing from the rate could round
     * differently by a hundredth, and a reversal that does not net to zero is
     * worse than no reversal at all.
     */
    private function mirror(JournalLine $line): JournalLineDraft
    {
        if ($line->isDebit()) {
            return JournalLineDraft::credit(
                accountId: $line->account_id,
                amount: $line->debit,
                memo: $line->memo,
                contactId: $line->contact_id,
                itemId: $line->item_id,
                taxId: $line->tax_id,
                baseAmountOverride: $line->debit_base,
            );
        }

        return JournalLineDraft::debit(
            accountId: $line->account_id,
            amount: $line->credit,
            memo: $line->memo,
            contactId: $line->contact_id,
            itemId: $line->item_id,
            taxId: $line->tax_id,
            baseAmountOverride: $line->credit_base,
        );
    }
}
