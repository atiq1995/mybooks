<?php

declare(strict_types=1);

namespace App\Domain\Reports\Data;

use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Carbon;

/**
 * The span a report covers, and the span it is compared against.
 *
 * A comparison is not decoration. A profit figure on its own is a number; the
 * same figure beside last year is information, and it is the first thing
 * anybody reading a management account looks for. So the comparison is part
 * of the period rather than an option bolted on per report.
 *
 * Both comparisons on offer are honest ones:
 *
 *   the PRECEDING span of the same length — March against February;
 *   the SAME span a year earlier — March against last March, which is the
 *   one that survives seasonality.
 */
final readonly class ReportPeriod
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public string $label,
    ) {}

    /**
     * A named preset, resolved against the organisation's financial year.
     *
     * The fiscal year matters here: a business whose year runs July to June
     * means something different by "this year" than the calendar does, and
     * getting that wrong misstates every report built on it.
     */
    public static function preset(string $preset, Organization $organization, ?Carbon $today = null): self
    {
        $today ??= Carbon::now();
        $startMonth = $organization->fiscal_year_start_month ?? 1;

        return match ($preset) {
            'this_month' => new self(
                $today->copy()->startOfMonth(),
                $today->copy()->endOfMonth(),
                $today->format('F Y'),
            ),
            'last_month' => self::month($today->copy()->subMonthNoOverflow()),
            'this_quarter' => new self(
                $today->copy()->firstOfQuarter(),
                $today->copy()->lastOfQuarter(),
                'Q'.$today->quarter.' '.$today->year,
            ),
            'last_quarter' => self::quarter($today->copy()->subQuarterNoOverflow()),
            'last_year' => self::fiscalYear(
                self::fiscalYearStart($today, $startMonth)->subYear(),
                $startMonth,
            ),
            'to_date' => new self(
                self::fiscalYearStart($today, $startMonth),
                $today->copy()->endOfDay(),
                'Year to date',
            ),
            default => self::fiscalYear(self::fiscalYearStart($today, $startMonth), $startMonth),
        };
    }

    /**
     * From the start of the financial year containing a date, up to it.
     *
     * What the balance sheet needs to work out this year's earnings, and what
     * the cash flow covers by default. It has to follow the organisation's
     * own year: a business running July to June means something different by
     * "this year" than the calendar does, and using January would put six
     * months of last year's profit into this year's equity.
     */
    public static function fiscalYearToDate(Organization $organization, Carbon $asOf): self
    {
        $start = self::fiscalYearStart($asOf, $organization->fiscal_year_start_month ?? 1);

        return new self($start, $asOf->copy()->endOfDay(), 'Year to '.$asOf->format('j M Y'));
    }

    public static function custom(Carbon $from, Carbon $to): self
    {
        return new self(
            $from->copy()->startOfDay(),
            $to->copy()->endOfDay(),
            $from->format('j M Y').' – '.$to->format('j M Y'),
        );
    }

    public static function month(Carbon $anyDayIn): self
    {
        return new self(
            $anyDayIn->copy()->startOfMonth(),
            $anyDayIn->copy()->endOfMonth(),
            $anyDayIn->format('F Y'),
        );
    }

    public static function quarter(Carbon $anyDayIn): self
    {
        return new self(
            $anyDayIn->copy()->firstOfQuarter(),
            $anyDayIn->copy()->lastOfQuarter(),
            'Q'.$anyDayIn->quarter.' '.$anyDayIn->year,
        );
    }

    public static function fiscalYear(Carbon $start, int $startMonth): self
    {
        $end = $start->copy()->addYear()->subDay();

        return new self(
            $start->copy(),
            $end,
            $startMonth === 1
                ? (string) $start->year
                : $start->format('M Y').' – '.$end->format('M Y'),
        );
    }

    /**
     * The preceding span of the same length.
     *
     * Counted in days rather than months, so a comparison is always like for
     * like: "the previous month" against a 31-day period that started
     * mid-month is a different question from the one the reader asked.
     */
    public function previous(): self
    {
        $days = (int) $this->from->diffInDays($this->to->copy()->startOfDay());

        $to = $this->from->copy()->subDay()->endOfDay();
        $from = $to->copy()->startOfDay()->subDays($days);

        return new self($from, $to, self::describe($from, $to));
    }

    /** The same span, a year earlier. */
    public function sameSpanLastYear(): self
    {
        $from = $this->from->copy()->subYearNoOverflow();
        $to = $this->to->copy()->subYearNoOverflow();

        return new self($from, $to, self::describe($from, $to));
    }

    /**
     * The comparison a caller asked for, or none.
     */
    public function comparison(?string $mode): ?self
    {
        return match ($mode) {
            'previous' => $this->previous(),
            'last_year' => $this->sameSpanLastYear(),
            default => null,
        };
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /**
     * @return array{from: string, to: string, label: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->fromDate(),
            'to' => $this->toDate(),
            'label' => $this->label,
        ];
    }

    private static function fiscalYearStart(Carbon $today, int $startMonth): Carbon
    {
        $start = $today->copy()->startOfDay()->setMonth($startMonth)->startOfMonth();

        return $start->greaterThan($today) ? $start->subYear() : $start;
    }

    private static function describe(Carbon $from, Carbon $to): string
    {
        if ($from->isSameMonth($to) && $from->isSameDay($from->copy()->startOfMonth())
            && $to->isSameDay($to->copy()->endOfMonth())) {
            return $from->format('F Y');
        }

        return $from->format('j M Y').' – '.$to->format('j M Y');
    }
}
