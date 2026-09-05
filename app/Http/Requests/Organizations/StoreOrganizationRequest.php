<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Domain\Organizations\Support\OrganizationOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating an organisation.
 *
 * Any verified, signed-in user may create one — they become its owner. There
 * is no organisation-scoped permission to check, because at this point no
 * organisation exists to check it against.
 */
final class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ! $user->isSuspended()
            && $user->hasVerifiedEmail();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],

            'country_code' => ['required', 'string', Rule::in(OrganizationOptions::countryCodes())],

            /*
             * The base currency cannot be changed once anything is posted:
             * every journal line stores a base-currency amount computed at
             * posting time. Validated against the supported list rather than
             * accepted as free text.
             */
            'base_currency' => ['required', 'string', Rule::in(OrganizationOptions::currencyCodes())],

            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],

            'timezone' => ['required', 'string', Rule::in(OrganizationOptions::timezoneIdentifiers())],
            'locale' => ['nullable', 'string', 'max:10'],
            'rounding_mode' => [
                'nullable',
                Rule::in(['HALF_UP', 'HALF_DOWN', 'HALF_EVEN', 'UP', 'DOWN']),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'base_currency.in' => 'Choose a supported base currency. This cannot be changed once you start posting.',
            'fiscal_year_start_month.between' => 'The financial year has to start in one of the twelve months.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'base_currency' => 'base currency',
            'country_code' => 'country',
            'fiscal_year_start_month' => 'financial year start',
        ];
    }
}
