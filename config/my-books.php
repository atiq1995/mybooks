<?php

declare(strict_types=1);

/*
|---------------------------------------------------------------------------
| My Books
|---------------------------------------------------------------------------
|
| Application-level settings. Anything an ORGANISATION can differ on lives on
| the organisation record, not here — two organisations in one deployment may
| file in different jurisdictions and keep books in different currencies.
|
| These are deployment-wide defaults and limits.
*/

return [

    /*
     * Seed values applied when a NEW organisation is created. Once it exists,
     * its own settings govern.
     */
    'defaults' => [
        'jurisdiction' => env('MY_BOOKS_DEFAULT_JURISDICTION', 'PK'),
        'currency' => env('MY_BOOKS_DEFAULT_CURRENCY', 'PKR'),
        'timezone' => env('MY_BOOKS_DEFAULT_TIMEZONE', 'Asia/Karachi'),

        /*
         * Rounding is an accounting policy, not a formatting preference:
         * changing it changes reported figures. This only seeds a new
         * organisation's setting. See ACCOUNTING_RULES.md §2.
         */
        'rounding' => env('MY_BOOKS_DEFAULT_ROUNDING', 'HALF_UP'),

        /* Pakistan's tax year runs July to June. */
        'fiscal_year_start_month' => (int) env('MY_BOOKS_DEFAULT_FISCAL_MONTH', 7),
    ],

    'login' => [
        'max_attempts' => (int) env('MY_BOOKS_LOGIN_MAX_ATTEMPTS', 5),
        'decay_minutes' => (int) env('MY_BOOKS_LOGIN_DECAY_MINUTES', 15),
    ],

    'security' => [
        /*
         * Require a confirmed second factor for the consequential permissions
         * — posting to the ledger, moving money, changing who has access.
         * See Permission::requiringTwoFactor().
         *
         * Defaults OFF in local and testing so a freshly seeded account works
         * without enrolling an authenticator first, and ON everywhere else.
         * Setting MY_BOOKS_ENFORCE_2FA explicitly overrides both.
         *
         * Resolved to a real boolean HERE rather than in the provider: an
         * unset env() yields null, and a null config value cannot be read
         * back as a boolean no matter what default the reader passes.
         */
        'enforce_two_factor' => (bool) env(
            'MY_BOOKS_ENFORCE_2FA',
            ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),
        ),
    ],

    'api' => [
        'rate_limit' => (int) env('MY_BOOKS_API_RATE_LIMIT', 120),
    ],

    /*
     * Money precision. These are STORAGE scales and match the column
     * definitions; presentation rounds to the currency's own precision.
     * Changing them requires a migration, not just an edit here.
     */
    'precision' => [
        'money' => 4,
        'quantity' => 6,
        'rate' => 10,
    ],
];
