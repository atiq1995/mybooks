<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new account in the chart.
 *
 * The code is unique per organisation and is what accountants navigate by, so
 * the uniqueness check is scoped through the Eloquent model rather than a raw
 * unique rule — the organisation scope applies, and a code used by another
 * organisation stays available here.
 *
 * @see ACCOUNTING_RULES.md §3
 */
final class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::AccountingManageAccounts->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:20',
                // No spaces: a code is sorted, searched and typed, and a
                // trailing space makes two accounts look identical.
                'regex:/^[A-Za-z0-9._\-\/]+$/',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (Account::query()->where('code', $value)->exists()) {
                        $fail('An account with this code already exists.');
                    }
                },
            ],

            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],

            'type' => ['required', Rule::in(AccountType::values())],
            'subtype' => ['nullable', 'string', 'max:40'],

            // Stored rather than derived, so a contra account can invert its
            // type. The form defaults it from the type.
            'normal_balance' => ['required', 'in:debit,credit'],

            'parent_id' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    $parent = Account::query()->whereKey($value)->first();

                    if ($parent === null) {
                        $fail('Choose a heading that exists.');

                        return;
                    }

                    if (! $parent->is_header) {
                        $fail("{$parent->code} {$parent->name} is not a heading, so it cannot group accounts.");
                    }
                },
            ],

            // ISO 4217. Only bank and cash accounts need one; everything else
            // is denominated in the organisation's base currency.
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],

            'is_header' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Use letters, digits, dots, dashes or slashes — no spaces.',
            'currency.regex' => 'Use a three-letter currency code, such as USD.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $currency = $this->input('currency');

        $this->merge([
            'code' => is_string($code = $this->input('code')) ? trim($code) : $code,
            'currency' => is_string($currency) && $currency !== ''
                ? mb_strtoupper(trim($currency))
                : null,
        ]);
    }
}
