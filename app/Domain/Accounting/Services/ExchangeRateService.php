<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Exceptions\MissingExchangeRate;
use App\Domain\Accounting\Models\ExchangeRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * Finding the rate to use.
 *
 * The rule is "the latest rate on or before the date", never the newest rate
 * available. Converting a January invoice at today's rate would restate
 * history every time somebody entered a new rate, which is precisely what
 * storing `base_amount` at posting time exists to prevent.
 *
 * A pair with no rate is an error rather than a guess. Silently falling back
 * to 1, or to an inverted rate, produces a figure that looks plausible and is
 * wrong by whatever the currencies have moved.
 *
 * @see ACCOUNTING_RULES.md §8
 */
final readonly class ExchangeRateService
{
    /**
     * The rate to convert `$from` into `$to` on `$on`, as a decimal string.
     *
     * @throws MissingExchangeRate
     */
    public function rate(string $from, string $to, ?Carbon $on = null): string
    {
        $on ??= Carbon::now();

        // A currency converts to itself at 1. Storing that would only invite
        // it being wrong, and a database constraint refuses the row.
        if ($from === $to) {
            return '1';
        }

        $direct = $this->lookup($from, $to, $on);

        if ($direct !== null) {
            return $direct->rate;
        }

        /*
         * The inverse, if that is what was recorded. Someone who enters
         * "1 USD = 278.50 PKR" has answered "how many PKR per USD" for both
         * directions, and asking them to enter both invites the two drifting
         * apart.
         */
        $inverse = $this->lookup($to, $from, $on);

        if ($inverse !== null) {
            return (string) BigDecimal::one()
                ->dividedBy(BigDecimal::of($inverse->rate), 10, RoundingMode::HalfUp);
        }

        throw MissingExchangeRate::forPair($from, $to, $on->toDateString());
    }

    /**
     * Convert an amount, rounded once to money scale at the end.
     */
    public function convert(string $amount, string $from, string $to, ?Carbon $on = null): string
    {
        return (string) BigDecimal::of($amount)
            ->multipliedBy(BigDecimal::of($this->rate($from, $to, $on)))
            ->toScale(4, RoundingMode::HalfUp);
    }

    public function has(string $from, string $to, ?Carbon $on = null): bool
    {
        $on ??= Carbon::now();

        return $from === $to
            || $this->lookup($from, $to, $on) !== null
            || $this->lookup($to, $from, $on) !== null;
    }

    private function lookup(string $from, string $to, Carbon $on): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->whereDate('effective_on', '<=', $on->toDateString())
            // The latest one that had taken effect by then.
            ->orderByDesc('effective_on')
            ->first();
    }
}
