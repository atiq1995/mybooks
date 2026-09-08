<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Models\Account;
use App\Domain\Catalog\Models\Item;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Tax\Models\Tax;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A sales document and its lines.
 *
 * Shape only. Whether the arithmetic is right belongs to the tax engine and
 * whether it may post belongs to the ledger — this exists so both receive
 * well-formed input, and so a mistake lands on the field that caused it.
 *
 * Every reference is checked through its Eloquent model, so the organisation
 * scope applies: an id from another organisation is "invalid", never
 * "forbidden", and the error reveals nothing about whether it exists
 * elsewhere.
 *
 * @see ACCOUNTING_RULES.md §5
 */
final class StoreSalesDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->isMethod('POST')
            ? Permission::SalesCreate
            : Permission::SalesUpdate;

        return $this->user()?->can($permission->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'contact_id' => [
                'required',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    $contact = Contact::query()->usable()->customers()->whereKey(self::asId($value))->first();

                    if ($contact === null) {
                        $fail('Choose a customer who is active.');
                    }
                },
            ],

            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issue_date'],

            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],

            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            // Ten decimal places: a rate multiplied by a large amount needs
            // more precision than the result does.
            'exchange_rate' => ['nullable', 'string', 'decimal:0,10'],

            'prices_include_tax' => ['nullable', 'boolean'],

            'discount_type' => ['nullable', 'in:percentage,amount'],
            'discount_value' => ['nullable', 'string', 'decimal:0,4', 'required_with:discount_type'],

            // Only a credit note carries this, and the controller ignores it
            // on anything else.
            'credits_document_id' => ['nullable', 'uuid'],

            'lines' => ['required', 'array', 'min:1', 'max:200'],

            'lines.*.item_id' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (Item::query()->usable()->whereKey(self::asId($value))->first() === null) {
                        $fail('Choose an item that is active, or clear it and type the line.');
                    }
                },
            ],

            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],

            /*
             * Six decimal places on quantity: an hourly rate billed in tenths
             * and a bulk price per gram both fit without rounding at entry.
             * A string, because a JSON number is a double.
             */
            'lines.*.quantity' => ['required', 'string', 'decimal:0,6'],
            'lines.*.unit_price' => ['required', 'string', 'decimal:0,4'],

            'lines.*.discount_type' => ['nullable', 'in:percentage,amount'],
            'lines.*.discount_value' => [
                'nullable',
                'string',
                'decimal:0,4',
                'required_with:lines.*.discount_type',
            ],

            'lines.*.tax_id' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (Tax::query()->usable()->forSales()->whereKey(self::asId($value))->first() === null) {
                        $fail('Choose a tax that applies to sales.');
                    }
                },
            ],

            'lines.*.revenue_account_id' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    $account = Account::query()->postable()->whereKey(self::asId($value))->first();

                    if ($account === null) {
                        $fail('Choose an account that accepts postings.');

                        return;
                    }

                    /*
                     * Revenue only. A sales line crediting an expense account
                     * balances perfectly and makes the profit and loss
                     * meaningless, so it is refused here rather than left to
                     * be noticed at year end.
                     */
                    if ($account->type->value !== 'income') {
                        $fail("{$account->code} {$account->name} is not a revenue account.");
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'A document needs at least one line.',
            'lines.min' => 'A document needs at least one line.',
            'lines.*.quantity.decimal' => 'Enter a quantity with up to six decimal places.',
            'lines.*.unit_price.decimal' => 'Enter a price with up to four decimal places.',
            'due_date.after_or_equal' => 'A document cannot fall due before it was issued.',
        ];
    }

    /**
     * Amounts arrive as strings whatever the client sent.
     *
     * A JSON number is a double, and a double cannot hold 0.1 exactly. If one
     * reaches here it is stringified before validation rather than silently
     * losing a fraction.
     */
    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        $this->merge([
            'lines' => array_map(
                static function (mixed $line): mixed {
                    if (! is_array($line)) {
                        return $line;
                    }

                    foreach (['quantity', 'unit_price', 'discount_value'] as $field) {
                        if (! isset($line[$field])) {
                            continue;
                        }

                        $value = $line[$field];

                        $line[$field] = is_string($value)
                            ? trim($value)
                            : (is_scalar($value) ? (string) $value : null);

                        // A blank is "no discount", not "a discount of
                        // nothing" — and the two behave differently.
                        if ($line[$field] === '') {
                            $line[$field] = null;
                        }
                    }

                    // A discount value with no type is half a decision, and a
                    // database constraint refuses it.
                    if (($line['discount_value'] ?? null) === null) {
                        $line['discount_type'] = null;
                    }

                    return $line;
                },
                $lines,
            ),
        ]);

        if ($this->input('discount_value') === '' || $this->input('discount_value') === null) {
            $this->merge(['discount_type' => null, 'discount_value' => null]);
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
