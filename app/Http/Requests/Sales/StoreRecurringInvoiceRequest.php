<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Models\Account;
use App\Domain\Catalog\Models\Item;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Sales\Enums\RecurrenceFrequency;
use App\Domain\Tax\Models\Tax;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A recurring-invoice template and its lines.
 *
 * `next_run_on` is deliberately absent: it is working state, derived by the
 * action from the schedule and what has already been billed. Accepting it
 * from a form would let somebody skip or repeat a billing period by typing a
 * date.
 *
 * @see ACCOUNTING_RULES.md §6
 */
final class StoreRecurringInvoiceRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:120'],

            'contact_id' => [
                'required',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    $contact = Contact::query()
                        ->usable()
                        ->customers()
                        ->whereKey(self::asId($value))
                        ->first();

                    if ($contact === null) {
                        $fail('Choose a customer who is active.');
                    }
                },
            ],

            'frequency' => ['required', 'in:'.implode(',', RecurrenceFrequency::values())],
            /*
             * Capped at 52, which is a year of weeks. A larger interval is
             * always better expressed as a different frequency, and an
             * unbounded one lets somebody schedule an invoice for the next
             * century by mistyping.
             */
            'interval' => ['nullable', 'integer', 'between:1,52'],

            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'max_occurrences' => ['nullable', 'integer', 'between:1,1000'],

            'auto_issue' => ['nullable', 'boolean'],
            'payment_terms_days' => ['nullable', 'integer', 'between:0,365'],

            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'exchange_rate' => ['nullable', 'string', 'decimal:0,10'],
            'prices_include_tax' => ['nullable', 'boolean'],

            'discount_type' => ['nullable', 'in:percentage,amount'],
            'discount_value' => ['nullable', 'string', 'decimal:0,4', 'required_with:discount_type'],

            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],

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

                    // Revenue only, exactly as on a one-off invoice: this
                    // template produces invoices, and they post the same way.
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
            'lines.required' => 'A recurring invoice needs at least one line.',
            'lines.min' => 'A recurring invoice needs at least one line.',
            'ends_on.after_or_equal' => 'A schedule cannot end before it starts.',
        ];
    }

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

                        // A JSON number is a double, and a double cannot hold
                        // 0.1 exactly.
                        $line[$field] = is_string($value)
                            ? trim($value)
                            : (is_scalar($value) ? (string) $value : null);

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
    }

    private static function asId(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
