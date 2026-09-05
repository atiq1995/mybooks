<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Domain\Access\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The business details that appear on invoices, bills and statements.
 *
 * Everything here is editable later in settings — nothing collected at this
 * step is irreversible, unlike the base currency chosen at creation.
 */
final class UpdateOnboardingDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::OrganizationUpdate->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['nullable', 'string', 'max:160'],

            /*
             * Pakistan: NTN (income tax) and STRN (sales tax). Named
             * generically so other jurisdictions map onto the same columns.
             * Deliberately loose — formats differ by country and a strict
             * pattern here would reject valid numbers elsewhere.
             */
            'tax_registration_number' => ['nullable', 'string', 'max:50'],
            'sales_tax_registration_number' => ['nullable', 'string', 'max:50'],
            'business_registration_number' => ['nullable', 'string', 'max:50'],

            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:160'],
            'website' => ['nullable', 'url', 'max:200'],

            // Address shapes differ enough between countries that a fixed
            // column layout is wrong for most of them.
            'address' => ['nullable', 'array'],
            'address.line1' => ['nullable', 'string', 'max:160'],
            'address.line2' => ['nullable', 'string', 'max:160'],
            'address.city' => ['nullable', 'string', 'max:80'],
            'address.state' => ['nullable', 'string', 'max:80'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'website.url' => 'Enter a full web address, including https://',
        ];
    }
}
