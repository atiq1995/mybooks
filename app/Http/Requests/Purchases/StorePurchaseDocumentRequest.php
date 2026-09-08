<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchases;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Models\Account;
use App\Domain\Catalog\Models\Item;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Tax\Models\Tax;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A purchase document and its lines.
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
 * @see ACCOUNTING_RULES.md §4.6, §5
 */
final class StorePurchaseDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->isMethod('POST')
            ? Permission::PurchasesCreate
            : Permission::PurchasesUpdate;

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
                    $contact = Contact::query()
                        ->usable()
                        ->vendors()
                        ->whereKey(self::asId($value))
                        ->first();

                    if ($contact === null) {
                        $fail('Choose a vendor who is active.');
                    }
                },
            ],

            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issue_date'],

            // The vendor's own number for this bill.
            'vendor_reference' => ['nullable', 'string', 'max:80'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],

            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'exchange_rate' => ['nullable', 'string', 'decimal:0,10'],

            'prices_include_tax' => ['nullable', 'boolean'],

            'discount_type' => ['nullable', 'in:percentage,amount'],
            'discount_value' => ['nullable', 'string', 'decimal:0,4', 'required_with:discount_type'],

            // Only a vendor credit carries this; the controller ignores it on
            // anything else.
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
                    $tax = Tax::query()
                        ->usable()
                        ->forPurchase()
                        ->whereKey(self::asId($value))
                        ->first();

                    if ($tax === null) {
                        $fail('Choose a tax that applies to purchases.');
                    }
                },
            ],

            /*
             * Whether this line's input tax can be reclaimed.
             *
             * Validated as a plain boolean and nothing more: which purchases
             * carry blocked input tax is a matter of local tax law that
             * changes without notice, and encoding today's list here would
             * make the software wrong the first time it changed. The person
             * entering the bill knows; the software records what they say and
             * — crucially — posts it correctly either way.
             */
            'lines.*.tax_is_claimable' => ['nullable', 'boolean'],

            'lines.*.debit_account_id' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    $account = Account::query()->postable()->whereKey(self::asId($value))->first();

                    if ($account === null) {
                        $fail('Choose an account that accepts postings.');

                        return;
                    }

                    /*
                     * Expense or asset, and nothing else.
                     *
                     * Wider than the sales side's "income only", because a
                     * purchase genuinely lands in either: an expense for
                     * consumables, an asset for stock or equipment. What it
                     * never does is debit income, a liability or equity —
                     * each of which would balance perfectly and put the cost
                     * somewhere no report would ever show it.
                     */
                    if (! in_array($account->type->value, ['expense', 'asset'], true)) {
                        $fail(sprintf(
                            '%s %s is a %s account. A purchase line has to be charged to an '.
                            'expense account, or to an asset account if what was bought is '.
                            'still worth something.',
                            $account->code,
                            $account->name,
                            $account->type->value,
                        ));
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
            'due_date.after_or_equal' => 'A bill cannot fall due before it was issued.',
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
     */
    private static function asId(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
