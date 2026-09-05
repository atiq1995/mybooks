<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Data;

/**
 * The information needed to bring an organisation into existence.
 *
 * Only the decisions that are hard to change later: the base currency is
 * immutable once anything is posted, and the fiscal year start determines how
 * every period is cut. Everything else — address, logo, tax registration
 * numbers — is editable afterwards and is collected by the onboarding wizard.
 */
final readonly class NewOrganizationData
{
    public function __construct(
        public string $name,
        public ?string $legalName,
        public string $countryCode,
        public string $baseCurrency,
        public string $jurisdiction,
        public int $fiscalYearStartMonth,
        public string $timezone,
        public string $locale,
        public string $roundingMode,
    ) {}

    /**
     * Build from a validated payload.
     *
     * Values are narrowed rather than cast: the array is typed `mixed` at this
     * boundary, and a cast from mixed hides the case where validation was
     * bypassed. Every field here has already passed StoreOrganizationRequest,
     * so the fallbacks are belt-and-braces rather than real behaviour.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $country = mb_strtoupper(self::string($validated, 'country_code', 'PK'));
        $legalName = trim(self::string($validated, 'legal_name'));

        return new self(
            name: trim(self::string($validated, 'name')),
            legalName: $legalName === '' ? null : $legalName,
            countryCode: $country,
            baseCurrency: mb_strtoupper(self::string($validated, 'base_currency', 'PKR')),
            /*
             * The tax rule pack. Only PK ships today; others arrive in
             * Phase 11, so anything else is stored as the country's own code
             * and treated as "no pack" by the tax engine.
             */
            jurisdiction: $country,
            fiscalYearStartMonth: self::integer($validated, 'fiscal_year_start_month', 1),
            timezone: self::string(
                $validated,
                'timezone',
                config()->string('my-books.defaults.timezone'),
            ),
            locale: self::string($validated, 'locale', 'en'),
            roundingMode: self::string(
                $validated,
                'rounding_mode',
                config()->string('my-books.defaults.rounding'),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function integer(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? (int) $value
            : $default;
    }
}
