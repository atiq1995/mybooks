<?php

declare(strict_types=1);

namespace App\Domain\Banking\Services;

use App\Domain\Banking\Data\MatchSuggestion;
use App\Domain\Banking\Models\BankStatementLine;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Candidate matches for a statement line. Suggestions only.
 *
 * Nothing in this class writes anything, and that is the design rather than
 * an accident of implementation: §8 requires a match to be an explicit,
 * permissioned, audited act, and the cheapest way to guarantee it is for the
 * suggester to have no capability to record one.
 *
 * **The amount must be exact.** Not "close", not "within rounding" — exact.
 * A near-amount suggestion is how a reconciliation gets forced to zero while
 * being wrong: somebody accepts a 12,450 against a 12,540, the difference
 * vanishes into a matched pair, and the error is now invisible. A statement
 * line that has no exact counterpart is telling you something true, and the
 * screen says so instead.
 *
 * The date is a window rather than an equality, because a payment made on
 * Friday reaches the bank on Monday, and that gap is the normal case rather
 * than an anomaly.
 */
final readonly class MatchSuggester
{
    /** Days either side of the statement date worth considering. */
    private const WINDOW_DAYS = 30;

    /** Most suggestions offered for one line. */
    private const LIMIT = 8;

    /**
     * @return list<MatchSuggestion>
     */
    public function for(BankStatementLine $line, int $limit = self::LIMIT): array
    {
        $bankAccount = $line->bankAccountOrFail();
        $amount = BigDecimal::of($line->amount)->toScale(4);

        $from = $line->transaction_date->copy()->subDays(self::WINDOW_DAYS)->toDateString();
        $to = $line->transaction_date->copy()->addDays(self::WINDOW_DAYS)->toDateString();

        /**
         * Every posted line on this account in the window that nothing has
         * claimed yet.
         *
         * `debit - credit` is the signed amount, which is the same convention
         * the statement uses: money into the account is positive on both
         * sides, whether the account is an asset or a credit card.
         *
         * @var list<object{id: string, journal_entry_id: string, entry_no: string, entry_date: string, signed: string, memo: ?string, source_type: string, contact_name: ?string}> $candidates
         */
        $candidates = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->leftJoin('contacts as c', 'c.id', '=', 'jl.contact_id')
            ->leftJoin('bank_transaction_matches as m', 'm.journal_line_id', '=', 'jl.id')
            ->where('jl.organization_id', $line->organization_id)
            ->where('jl.account_id', $bankAccount->account_id)
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->whereNull('m.id')
            ->whereRaw('jl.debit - jl.credit = ?', [(string) $amount])
            ->orderBy('je.entry_date')
            ->limit($limit * 4)
            ->get([
                'jl.id',
                'jl.journal_entry_id',
                'je.entry_no',
                'je.entry_date',
                'jl.memo',
                'je.source_type',
                'c.display_name as contact_name',
                DB::raw('(jl.debit - jl.credit) as signed'),
            ])
            ->all();

        $suggestions = [];

        foreach ($candidates as $candidate) {
            /** @var object{id: string, journal_entry_id: string, entry_no: string, entry_date: string, signed: string, memo: ?string, source_type: string, contact_name: ?string} $candidate */
            $entryDate = Carbon::parse($candidate->entry_date);

            [$confidence, $reasons] = $this->score($line, $entryDate, $candidate->memo, $candidate->contact_name);

            $suggestions[] = new MatchSuggestion(
                journalLineId: $candidate->id,
                journalEntryId: $candidate->journal_entry_id,
                entryNo: $candidate->entry_no,
                entryDate: $entryDate,
                amount: (string) BigDecimal::of($candidate->signed)->toScale(4),
                memo: $candidate->memo,
                sourceType: $candidate->source_type,
                contactName: $candidate->contact_name,
                confidence: $confidence,
                reasons: $reasons,
            );
        }

        usort(
            $suggestions,
            static fn (MatchSuggestion $a, MatchSuggestion $b): int => $b->confidence <=> $a->confidence
                ?: $a->entryDate <=> $b->entryDate,
        );

        return array_slice($suggestions, 0, $limit);
    }

    /**
     * How confident, and — more usefully — why.
     *
     * @return array{0: int, 1: list<string>}
     */
    private function score(
        BankStatementLine $line,
        Carbon $entryDate,
        ?string $memo,
        ?string $contactName,
    ): array {
        $reasons = ['The amount is exactly the same.'];

        $days = (int) abs($line->transaction_date->diffInDays($entryDate));

        $confidence = match (true) {
            $days === 0 => 70,
            $days <= 3 => 60,
            $days <= 7 => 50,
            $days <= 14 => 40,
            default => 30,
        };

        $reasons[] = match (true) {
            $days === 0 => 'Same date.',
            $days === 1 => 'One day apart.',
            default => "{$days} days apart.",
        };

        $haystack = mb_strtolower(implode(' ', array_filter([
            $line->description,
            $line->reference,
            $line->payee,
        ])));

        if ($line->reference !== null && $memo !== null
            && str_contains(mb_strtolower($memo), mb_strtolower($line->reference))) {
            $confidence += 20;
            $reasons[] = "The reference {$line->reference} appears in the entry.";
        }

        if ($contactName !== null && $this->mentions($haystack, $contactName)) {
            $confidence += 15;
            $reasons[] = "The statement mentions {$contactName}.";
        } elseif ($memo !== null && $this->mentions($haystack, $memo)) {
            $confidence += 10;
            $reasons[] = 'The description and the entry memo overlap.';
        }

        return [min(100, $confidence), $reasons];
    }

    /**
     * Whether a name turns up in the statement text.
     *
     * Word by word, ignoring the short ones. Bank descriptions mangle names
     * beyond what a substring match survives — "KARACHI TEXTILES LTD" arrives
     * as "TFR KARACHI TEXTILE 0098" — and a single distinctive word is a far
     * better signal than the whole string.
     */
    private function mentions(string $haystack, string $needle): bool
    {
        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($needle)) ?: [];

        foreach ($words as $word) {
            if (mb_strlen($word) >= 4 && str_contains($haystack, $word)) {
                return true;
            }
        }

        return false;
    }
}
