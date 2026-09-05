<?php

declare(strict_types=1);

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Money\Context\CustomContext;
use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a numeric(19,4) column to Brick\Money and back.
 *
 * Money in this application is never a float, at any point, in any layer.
 * PostgreSQL returns numeric columns as strings; this cast keeps them as
 * strings all the way into BigDecimal, so no value ever passes through
 * PHP's binary floating point.
 *
 * The currency comes from a sibling column, because an amount without its
 * currency is not money — it is a number that will eventually be added to
 * the wrong thing.
 *
 * Usage:
 *
 *     protected function casts(): array
 *     {
 *         return [
 *             'total'      => MoneyCast::class.':currency',
 *             'base_total' => MoneyCast::class.':base_currency',
 *         ];
 *     }
 *
 * @implements CastsAttributes<Money|null, Money|BigDecimal|string|int|null>
 */
final readonly class MoneyCast implements CastsAttributes
{
    /**
     * Storage scale. Four decimal places, matching numeric(19,4).
     *
     * Deliberately more than the two places most currencies present: unit
     * prices, tax components and FX conversions all produce intermediate
     * values that must survive without being rounded on the way into the
     * database. Presentation rounds; storage does not.
     */
    public const int SCALE = 4;

    public function __construct(
        private string $currencyAttribute = 'currency',
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        // PostgreSQL returns numeric as a string. Anything else reaching here
        // means a driver or cast upstream has already lost precision.
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException(
                "Money attribute [{$key}] on ".$model::class.' was read as '.get_debug_type($value).
                '; expected a decimal string from the database.'
            );
        }

        return Money::of(
            // (string) rather than a cast to float — the whole point.
            BigDecimal::of((string) $value),
            $this->resolveCurrency($model, $key, $attributes),
            new CustomContext(self::SCALE),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        $amount = match (true) {
            $value instanceof Money => $value->getAmount(),
            $value instanceof BigDecimal => $value,
            is_string($value), is_int($value) => BigDecimal::of($value),

            // A float reaching here means somewhere upstream did money
            // arithmetic in binary floating point. Fail loudly and early:
            // the alternative is a ledger that is wrong by fractions of a
            // unit in a way nobody notices for months.
            is_float($value) => throw new InvalidArgumentException(
                "Refusing to store a float in money attribute [{$key}] on ".
                $model::class.'. Use Brick\Money\Money, BigDecimal, or a decimal string. '.
                'See ACCOUNTING_RULES.md §2.'
            ),

            default => throw new InvalidArgumentException(
                'Cannot cast '.get_debug_type($value)." to money for attribute [{$key}]."
            ),
        };

        // Currency mismatches are caught here rather than at the point the
        // two amounts are eventually, wrongly, added together.
        if ($value instanceof Money) {
            $expected = $this->resolveCurrency($model, $key, $attributes);
            $actual = $value->getCurrency()->getCurrencyCode();

            if ($actual !== $expected) {
                throw new InvalidArgumentException(
                    "Currency mismatch on [{$key}]: value is {$actual}, record is {$expected}."
                );
            }
        }

        return [$key => (string) $amount->toScale(self::SCALE)];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveCurrency(Model $model, string $key, array $attributes): string
    {
        $currency = $attributes[$this->currencyAttribute]
            ?? $model->getAttribute($this->currencyAttribute);

        if (! is_string($currency) || $currency === '') {
            throw new InvalidArgumentException(
                'Money attribute ['.$key.'] on '.$model::class.' needs currency column ['.
                $this->currencyAttribute.'], which is missing or empty. '.
                'An amount without a currency is not money.'
            );
        }

        return $currency;
    }
}
