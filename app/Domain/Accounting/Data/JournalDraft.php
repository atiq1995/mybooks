<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Exceptions\UnbalancedJournal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;

/**
 * A journal entry that has not been posted yet.
 *
 * This is the boundary between business documents and the ledger. An invoice,
 * a bill, a payment — each produces one of these from a PURE function with no
 * database access, and {@see PostJournalEntry}
 * is the only thing that turns it into rows.
 *
 * That separation is what makes the entire posting rule set testable in
 * milliseconds and provably free of side effects: a draft can be built and
 * asserted against without a database at all.
 *
 * @see ACCOUNTING_RULES.md §4
 */
final readonly class JournalDraft
{
    /**
     * @param  list<JournalLineDraft>  $lines
     * @param  array{0: string, 1: ?string, 2: string}  $source  [type, id, purpose]
     */
    public function __construct(
        public DateTimeInterface $date,
        public string $currency,
        public string $baseCurrency,
        public string $exchangeRate,
        public array $lines,
        public array $source,
        public ?string $memo = null,
        public ?string $reversesEntryId = null,
    ) {}

    /**
     * Start a draft in the organisation's own currency, where the rate is 1
     * and the base amounts equal the transaction amounts.
     *
     * @param  list<JournalLineDraft>  $lines
     * @param  array{0: string, 1: ?string, 2: string}  $source
     */
    public static function inBaseCurrency(
        DateTimeInterface $date,
        string $currency,
        array $lines,
        array $source,
        ?string $memo = null,
    ): self {
        return new self(
            date: $date,
            currency: $currency,
            baseCurrency: $currency,
            exchangeRate: '1',
            lines: $lines,
            source: $source,
            memo: $memo,
        );
    }

    public function sourceType(): string
    {
        return $this->source[0];
    }

    public function sourceId(): ?string
    {
        return $this->source[1];
    }

    public function sourcePurpose(): string
    {
        return $this->source[2];
    }

    public function totalDebit(): BigDecimal
    {
        return $this->sum(static fn (JournalLineDraft $line): BigDecimal => $line->debitValue());
    }

    public function totalCredit(): BigDecimal
    {
        return $this->sum(static fn (JournalLineDraft $line): BigDecimal => $line->creditValue());
    }

    public function totalDebitBase(): BigDecimal
    {
        return $this->sum(fn (JournalLineDraft $line): BigDecimal => $line->debitBaseValue($this->exchangeRate));
    }

    public function totalCreditBase(): BigDecimal
    {
        return $this->sum(fn (JournalLineDraft $line): BigDecimal => $line->creditBaseValue($this->exchangeRate));
    }

    public function isBalanced(): bool
    {
        return $this->totalDebit()->isEqualTo($this->totalCredit())
            && $this->totalDebitBase()->isEqualTo($this->totalCreditBase());
    }

    /**
     * Throw unless this draft satisfies the invariants.
     *
     * Called by the ledger service before it writes anything — the first of
     * the three independent places the balance rule is enforced. The database
     * trigger and the nightly verifier are the other two.
     *
     * @throws UnbalancedJournal
     */
    public function assertBalanced(): void
    {
        if ($this->lines === []) {
            throw UnbalancedJournal::empty();
        }

        if (! $this->totalDebit()->isEqualTo($this->totalCredit())) {
            throw UnbalancedJournal::inTransactionCurrency(
                (string) $this->totalDebit(),
                (string) $this->totalCredit(),
                $this->currency,
            );
        }

        if (! $this->totalDebitBase()->isEqualTo($this->totalCreditBase())) {
            throw UnbalancedJournal::inBaseCurrency(
                (string) $this->totalDebitBase(),
                (string) $this->totalCreditBase(),
                $this->baseCurrency,
            );
        }

        if ($this->totalDebit()->isZero()) {
            throw UnbalancedJournal::zeroValue();
        }
    }

    /**
     * @param  callable(JournalLineDraft): BigDecimal  $extract
     */
    private function sum(callable $extract): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($this->lines as $line) {
            $total = $total->plus($extract($line));
        }

        // Four decimal places, matching numeric(19,4). Summing at full
        // precision and rounding once at the end is what keeps a long entry
        // from drifting by fractions of a unit.
        return $total->toScale(4, RoundingMode::HalfUp);
    }
}
