<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Support;

/**
 * Reference data for the organisation setup forms.
 *
 * A curated list rather than every ISO code. Presenting 180 currencies to
 * someone setting up a business in Karachi is worse than presenting the
 * fifteen they might plausibly pick, and the base currency is a decision
 * that cannot be changed once anything is posted — so the list is short and
 * ordered by likelihood, not alphabetically.
 *
 * The full ISO set arrives with the localisation work in Phase 11.
 */
final class OrganizationOptions
{
    /**
     * Country code => [label, default currency, default fiscal start month].
     *
     * Pakistan first: it is the first jurisdiction with a tax rule pack, and
     * its tax year runs July to June rather than matching the calendar.
     *
     * @var array<string, array{name: string, currency: string, fiscalMonth: int}>
     */
    private const array COUNTRIES = [
        'PK' => ['name' => 'Pakistan', 'currency' => 'PKR', 'fiscalMonth' => 7],
        'AE' => ['name' => 'United Arab Emirates', 'currency' => 'AED', 'fiscalMonth' => 1],
        'SA' => ['name' => 'Saudi Arabia', 'currency' => 'SAR', 'fiscalMonth' => 1],
        'IN' => ['name' => 'India', 'currency' => 'INR', 'fiscalMonth' => 4],
        'GB' => ['name' => 'United Kingdom', 'currency' => 'GBP', 'fiscalMonth' => 4],
        'US' => ['name' => 'United States', 'currency' => 'USD', 'fiscalMonth' => 1],
        'CA' => ['name' => 'Canada', 'currency' => 'CAD', 'fiscalMonth' => 1],
        'AU' => ['name' => 'Australia', 'currency' => 'AUD', 'fiscalMonth' => 7],
        'SG' => ['name' => 'Singapore', 'currency' => 'SGD', 'fiscalMonth' => 1],
        'MY' => ['name' => 'Malaysia', 'currency' => 'MYR', 'fiscalMonth' => 1],
        'BD' => ['name' => 'Bangladesh', 'currency' => 'BDT', 'fiscalMonth' => 7],
        'LK' => ['name' => 'Sri Lanka', 'currency' => 'LKR', 'fiscalMonth' => 1],
        'ZA' => ['name' => 'South Africa', 'currency' => 'ZAR', 'fiscalMonth' => 3],
        'DE' => ['name' => 'Germany', 'currency' => 'EUR', 'fiscalMonth' => 1],
        'NL' => ['name' => 'Netherlands', 'currency' => 'EUR', 'fiscalMonth' => 1],
    ];

    /**
     * @var array<string, string>
     */
    private const array CURRENCIES = [
        'PKR' => 'Pakistani Rupee',
        'AED' => 'UAE Dirham',
        'SAR' => 'Saudi Riyal',
        'INR' => 'Indian Rupee',
        'USD' => 'US Dollar',
        'EUR' => 'Euro',
        'GBP' => 'Pound Sterling',
        'CAD' => 'Canadian Dollar',
        'AUD' => 'Australian Dollar',
        'SGD' => 'Singapore Dollar',
        'MYR' => 'Malaysian Ringgit',
        'BDT' => 'Bangladeshi Taka',
        'LKR' => 'Sri Lankan Rupee',
        'ZAR' => 'South African Rand',
        'CNY' => 'Chinese Yuan',
        'JPY' => 'Japanese Yen',
    ];

    /**
     * @var array<string, string>
     */
    private const array TIMEZONES = [
        'Asia/Karachi' => 'Pakistan Standard Time (UTC+5)',
        'Asia/Dubai' => 'Gulf Standard Time (UTC+4)',
        'Asia/Riyadh' => 'Arabia Standard Time (UTC+3)',
        'Asia/Kolkata' => 'India Standard Time (UTC+5:30)',
        'Asia/Dhaka' => 'Bangladesh Standard Time (UTC+6)',
        'Asia/Colombo' => 'Sri Lanka (UTC+5:30)',
        'Asia/Singapore' => 'Singapore (UTC+8)',
        'Asia/Kuala_Lumpur' => 'Malaysia (UTC+8)',
        'Europe/London' => 'United Kingdom (UTC+0/+1)',
        'Europe/Berlin' => 'Central European Time (UTC+1/+2)',
        'Europe/Amsterdam' => 'Central European Time (UTC+1/+2)',
        'Africa/Johannesburg' => 'South Africa (UTC+2)',
        'America/New_York' => 'US Eastern (UTC-5/-4)',
        'America/Chicago' => 'US Central (UTC-6/-5)',
        'America/Los_Angeles' => 'US Pacific (UTC-8/-7)',
        'America/Toronto' => 'Canada Eastern (UTC-5/-4)',
        'Australia/Sydney' => 'Australia Eastern (UTC+10/+11)',
        'UTC' => 'UTC',
    ];

    /**
     * @return list<array{value: string, label: string, currency: string, fiscalMonth: int}>
     */
    public static function countries(): array
    {
        $out = [];

        foreach (self::COUNTRIES as $code => $meta) {
            $out[] = [
                'value' => $code,
                'label' => $meta['name'],
                'currency' => $meta['currency'],
                'fiscalMonth' => $meta['fiscalMonth'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function currencies(): array
    {
        $out = [];

        foreach (self::CURRENCIES as $code => $name) {
            $out[] = ['value' => $code, 'label' => "{$code} — {$name}"];
        }

        return $out;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function timezones(): array
    {
        $out = [];

        foreach (self::TIMEZONES as $identifier => $label) {
            $out[] = ['value' => $identifier, 'label' => $label];
        }

        return $out;
    }

    /**
     * Months, for choosing when the financial year begins.
     *
     * @return list<array{value: int, label: string}>
     */
    public static function months(): array
    {
        $names = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];

        $out = [];

        foreach ($names as $number => $name) {
            $out[] = ['value' => $number, 'label' => $name];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function countryCodes(): array
    {
        return array_keys(self::COUNTRIES);
    }

    /**
     * @return list<string>
     */
    public static function currencyCodes(): array
    {
        return array_keys(self::CURRENCIES);
    }

    /**
     * @return list<string>
     */
    public static function timezoneIdentifiers(): array
    {
        return array_keys(self::TIMEZONES);
    }

    /**
     * Everything the setup forms need, in one payload.
     *
     * @return array<string, mixed>
     */
    public static function forForms(): array
    {
        return [
            'countries' => self::countries(),
            'currencies' => self::currencies(),
            'timezones' => self::timezones(),
            'months' => self::months(),
            'defaults' => [
                'country_code' => config()->string('my-books.defaults.jurisdiction'),
                'base_currency' => config()->string('my-books.defaults.currency'),
                'timezone' => config()->string('my-books.defaults.timezone'),
                'fiscal_year_start_month' => config()->integer(
                    'my-books.defaults.fiscal_year_start_month',
                ),
            ],
        ];
    }
}
