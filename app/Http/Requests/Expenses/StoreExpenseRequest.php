<?php

declare(strict_types=1);

namespace App\Http\Requests\Expenses;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Expenses\Enums\ExpensePaymentMode;
use App\Domain\Tax\Models\Tax;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An expense and its lines.
 *
 * Shape only. Whether the arithmetic is right belongs to the tax engine and
 * whether it may post belongs to the ledger.
 *
 * Every reference is checked through its Eloquent model, so the organisation
 * scope applies: an id from another organisation is "invalid", never
 * "forbidden".
 *
 * @see ACCOUNTING_RULES.md §4.8, §5
 */
final class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->isMethod('POST')
            ? Permission::ExpensesCreate
            : Permission::ExpensesUpdate;

        return $this->user()?->can($permission->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /*
             * A contact is optional, and that is the point.
             *
             * Most expenses are a taxi or a hardware shop nobody will look
             * up. Requiring a contact record for every coffee is how an
             * expense screen stops being used, so the merchant name stands on
             * its own.
             */
            'contact_id' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (Contact::query()->usable()->whereKey(self::asId($value))->first() === null) {
                        $fail('Choose a contact who is active, or just type the merchant name.');
                    }
                },
            ],
            'merchant' => ['nullable', 'string', 'max:160'],

            'expense_date' => ['required', 'date'],

            'payment_mode' => ['required', 'in:'.implode(',', ExpensePaymentMode::values())],

            'paid_through_account_id' => [
                'nullable',
                'required_if:payment_mode,company',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    $account = Account::query()->postable()->whereKey(self::asId($value))->first();

                    if ($account === null) {
                        $fail('Choose an account that accepts postings.');

                        return;
                    }

                    /*
                     * Cash and bank only. Anything else would let somebody
                     * pay an expense "out of" a revenue account, which
                     * balances and is nonsense.
                     */
                    if (! in_array($account->subtype, ['cash', 'bank'], strict: true)) {
                        $fail("{$account->code} {$account->name} is not a bank or cash account.");
                    }
                },
            ],

            'reimburse_user_id' => [
                'nullable',
                'required_if:payment_mode,reimbursable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (User::query()->whereKey(self::asId($value))->first() === null) {
                        $fail('Choose the person to reimburse.');
                    }
                },
            ],

            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'exchange_rate' => ['nullable', 'string', 'decimal:0,10'],

            'prices_include_tax' => ['nullable', 'boolean'],

            'is_billable' => ['nullable', 'boolean'],
            'billable_contact_id' => [
                'nullable',
                'required_if:is_billable,true',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    $contact = Contact::query()
                        ->usable()
                        ->customers()
                        ->whereKey(self::asId($value))
                        ->first();

                    if ($contact === null) {
                        $fail('Choose the customer this will be rebilled to.');
                    }
                },
            ],

            'lines' => ['required', 'array', 'min:1', 'max:100'],

            'lines.*.kind' => ['nullable', 'in:amount,mileage'],
            'lines.*.description' => ['required', 'string', 'max:500'],

            'lines.*.quantity' => ['nullable', 'string', 'decimal:0,6'],
            /*
             * A distance for a mileage line, and the same column underneath.
             * Named separately in the request because "quantity: 120" reads
             * as twelve dozen of something.
             */
            'lines.*.distance' => ['nullable', 'string', 'decimal:0,6'],
            /*
             * Optional on a mileage line: left out, the rate in force on the
             * expense's date is looked up and copied onto the line.
             */
            'lines.*.unit_price' => [
                'nullable',
                'required_unless:lines.*.kind,mileage',
                'string',
                'decimal:0,4',
            ],
            'lines.*.unit' => ['nullable', 'in:km,mi'],

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
             * Whether this line's input tax can be reclaimed. §4.6: what
             * cannot be reclaimed is capitalised into the cost. Validated as
             * a plain boolean, because which purchases carry blocked input
             * tax is a matter of local law that changes without notice — the
             * person entering it knows, and the software's job is to post
             * what they say correctly either way.
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

                    // Expense or asset: a laptop is an asset, a taxi is not.
                    // What it never is, is income, a liability or equity.
                    if (! in_array($account->type->value, ['expense', 'asset'], true)) {
                        $fail(sprintf(
                            '%s %s is a %s account. Charge an expense to an expense '.
                            'account, or to an asset account if what was bought is still '.
                            'worth something.',
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
            'lines.required' => 'An expense needs at least one line.',
            'lines.min' => 'An expense needs at least one line.',
            'paid_through_account_id.required_if' => 'Say which account the money came out of.',
            'reimburse_user_id.required_if' => 'Say who is being reimbursed.',
            'billable_contact_id.required_if' => 'Say which customer this will be rebilled to.',
        ];
    }

    /**
     * Amounts arrive as strings whatever the client sent.
     */
    protected function prepareForValidation(): void
    {
        foreach (['exchange_rate'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $trimmed = trim($value);
                $this->merge([$field => $trimmed === '' ? null : $trimmed]);
            } elseif (is_int($value) || is_float($value)) {
                $this->merge([$field => (string) $value]);
            }
        }

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

                    foreach (['quantity', 'distance', 'unit_price'] as $field) {
                        if (! isset($line[$field])) {
                            continue;
                        }

                        $value = $line[$field];

                        // A JSON number is a double, and a double cannot hold
                        // 0.1 exactly.
                        $line[$field] = is_string($value)
                            ? trim($value)
                            : (is_scalar($value) ? (string) $value : null);

                        if ($line[$field] === '') {
                            $line[$field] = null;
                        }
                    }

                    return $line;
                },
                $lines,
            ),
        ]);
    }

    /**
     * An id, or an empty string when the input was not one.
     */
    private static function asId(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
