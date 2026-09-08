<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Domain\Access\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A rate for one currency pair on one day.
 *
 * @see ACCOUNTING_RULES.md §8
 */
final class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsAccounting->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from_currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'to_currency' => [
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                // A rate from a currency to itself is always 1 and is never
                // stored; storing one invites it being wrong.
                'different:from_currency',
            ],

            /*
             * Ten decimal places, as a string. Money needs four, but a rate
             * multiplied by a large amount needs more precision than the
             * result does — and a float would lose some of it on the way in.
             */
            'rate' => ['required', 'string', 'decimal:0,10'],

            'effective_on' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_currency.different' => 'A currency always converts to itself at 1, so there is nothing to record.',
            'rate.decimal' => 'Enter a rate with up to ten decimal places.',
            'from_currency.regex' => 'Use a three-letter currency code, such as USD.',
            'to_currency.regex' => 'Use a three-letter currency code, such as PKR.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['from_currency', 'to_currency'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $this->merge([$field => mb_strtoupper(trim($value))]);
            }
        }

        $rate = $this->input('rate');

        if (is_string($rate)) {
            $this->merge(['rate' => trim($rate)]);
        }
    }
}
