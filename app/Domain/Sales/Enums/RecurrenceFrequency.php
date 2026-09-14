<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

use Illuminate\Support\Carbon;

/**
 * How often a recurring invoice generates.
 *
 * Four frequencies and an interval, rather than a cron expression. "Every two
 * months" is what an arrangement actually says; a cron string would also
 * express "every Tuesday in March", which no invoicing agreement has needed
 * and which nothing downstream could present back to somebody.
 *
 * @see ACCOUNTING_RULES.md §6
 */
enum RecurrenceFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::Yearly => 'Yearly',
        };
    }

    /**
     * How the schedule reads with an interval applied.
     */
    public function describe(int $interval): string
    {
        if ($interval <= 1) {
            return $this->label();
        }

        return match ($this) {
            self::Weekly => "Every {$interval} weeks",
            self::Monthly => "Every {$interval} months",
            self::Quarterly => "Every {$interval} quarters",
            self::Yearly => "Every {$interval} years",
        };
    }

    /**
     * The nth occurrence of a schedule, counted from its start date.
     *
     * FROM THE ANCHOR, never from the previous occurrence, and month-end is
     * why. Two things go wrong otherwise, and the second is subtle enough to
     * have survived a first implementation of this method:
     *
     * Carbon's plain `addMonths` overflows — 31 January plus a month is 3
     * March, because February has no 31st — so an invoice billed on the 31st
     * would land on the 3rd and stay there. `addMonthsNoOverflow` clamps to
     * the last day of the shorter month instead, giving 28 February.
     *
     * But clamping is not enough on its own. Stepping forward one occurrence
     * at a time, 31 January becomes 28 February and then 28 MARCH: the
     * intended day is lost the moment it is clamped once, and every later
     * occurrence inherits the shorter month's day. Counting from the anchor
     * gives 28 February and then 31 March, which is what the agreement said.
     *
     * @param  int  $index  0 is the start date itself
     */
    public function occurrence(Carbon $anchor, int $index, int $interval = 1): Carbon
    {
        $interval = max(1, $interval);
        $steps = max(0, $index) * $interval;

        return match ($this) {
            self::Weekly => $anchor->copy()->addWeeks($steps),
            self::Monthly => $anchor->copy()->addMonthsNoOverflow($steps),
            self::Quarterly => $anchor->copy()->addMonthsNoOverflow($steps * 3),
            self::Yearly => $anchor->copy()->addYearsNoOverflow($steps),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $frequency): string => $frequency->value, self::cases());
    }
}
