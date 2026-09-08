<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contacts\Enums\ContactKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A customer or vendor.
 *
 * Names are deliberately NOT unique. Two genuinely different businesses can
 * share one, and refusing the second is worse than showing both — so the
 * screen warns about a near-duplicate and the database allows it.
 */
final class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->isMethod('POST')
            ? Permission::ContactsCreate
            : Permission::ContactsUpdate;

        return $this->user()?->can($permission->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(ContactKind::values())],

            'display_name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:200'],

            'email' => ['nullable', 'email:rfc', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'url', 'max:200'],

            /*
             * Pakistan: NTN and STRN. Deliberately loose — formats differ by
             * country, and a strict pattern would reject valid numbers
             * elsewhere.
             */
            'tax_registration_number' => ['nullable', 'string', 'max:50'],
            'sales_tax_registration_number' => ['nullable', 'string', 'max:50'],

            /*
             * Whether they file returns. Not a note: in Pakistan a
             * non-filer's withholding rate is roughly double a filer's, so
             * this changes arithmetic at payment time.
             */
            'is_tax_filer' => ['nullable', 'boolean'],

            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],

            // Beyond a year is a data-entry slip, not an arrangement.
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],

            'credit_limit' => ['nullable', 'string', 'decimal:0,4'],

            'billing_address' => ['nullable', 'array'],
            'billing_address.line1' => ['nullable', 'string', 'max:160'],
            'billing_address.line2' => ['nullable', 'string', 'max:160'],
            'billing_address.city' => ['nullable', 'string', 'max:80'],
            'billing_address.state' => ['nullable', 'string', 'max:80'],
            'billing_address.postal_code' => ['nullable', 'string', 'max:20'],
            'billing_address.country' => ['nullable', 'string', 'max:80'],

            'shipping_address' => ['nullable', 'array'],
            'shipping_address.line1' => ['nullable', 'string', 'max:160'],
            'shipping_address.line2' => ['nullable', 'string', 'max:160'],
            'shipping_address.city' => ['nullable', 'string', 'max:80'],
            'shipping_address.state' => ['nullable', 'string', 'max:80'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:20'],
            'shipping_address.country' => ['nullable', 'string', 'max:80'],

            'notes' => ['nullable', 'string', 'max:5000'],

            /*
             * Control account overrides, for the rare organisation keeping
             * receivables split by segment. Checked through the model so the
             * organisation scope applies.
             */
            'receivable_account_id' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    $account = Account::query()->postable()->whereKey(self::asId($value))->first();

                    if ($account === null || $account->type->value !== 'asset') {
                        $fail('A receivable override must be an asset account that accepts postings.');
                    }
                },
            ],
            'payable_account_id' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    $account = Account::query()->postable()->whereKey(self::asId($value))->first();

                    if ($account === null || $account->type->value !== 'liability') {
                        $fail('A payable override must be a liability account that accepts postings.');
                    }
                },
            ],

            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currency.regex' => 'Use a three-letter currency code, such as USD.',
            'payment_terms_days.max' => 'Terms beyond a year are almost certainly a typing slip.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $currency = $this->input('currency');

        $this->merge([
            'currency' => is_string($currency) && trim($currency) !== ''
                ? mb_strtoupper(trim($currency))
                : null,
        ]);

        // A blank credit limit is "no limit", not "a limit of nothing".
        if ($this->input('credit_limit') === '') {
            $this->merge(['credit_limit' => null]);
        }
    }

    /**
     * An id, or an empty string when the input was not one.
     *
     * A closure rule receives `mixed`. Handing that straight to `find()`
     * leaves the result ambiguous — it returns a collection for an array
     * argument — so it is narrowed here, and a non-string id simply does not
     * match anything.
     */
    private static function asId(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
