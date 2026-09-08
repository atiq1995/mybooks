<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Models\Account;
use App\Domain\Catalog\Enums\ItemKind;
use App\Domain\Catalog\Models\Item;
use App\Domain\Tax\Models\Tax;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Something you sell or buy.
 *
 * @see ACCOUNTING_RULES.md §4.1, §4.10
 */
final class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::InventoryManageItems->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(ItemKind::values())],

            'sku' => [
                'nullable',
                'string',
                'max:60',
                function (string $attribute, mixed $value, callable $fail): void {
                    // Unique per organisation, through the model so the scope
                    // applies. A SKU another organisation uses stays free.
                    $existing = Item::query()->where('sku', $value)->first();

                    $editing = $this->route('item');

                    if ($existing !== null && (! $editing instanceof Item || $existing->id !== $editing->id)) {
                        $fail("{$existing->name} already uses this SKU.");
                    }
                },
            ],

            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit' => ['nullable', 'string', 'max:20'],

            'sale_price' => ['nullable', 'string', 'decimal:0,4'],
            'purchase_price' => ['nullable', 'string', 'decimal:0,4'],

            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],

            'sales_account_id' => [
                'nullable',
                'uuid',
                $this->accountRule('income', 'A sales account must be a revenue account.'),
            ],
            'purchase_account_id' => [
                'nullable',
                'uuid',
                /*
                 * Expense OR asset: a service is expensed, and tracked goods
                 * capitalise into inventory. Both are legitimate, so both are
                 * allowed and the item's own `is_tracked` decides which is
                 * used.
                 */
                function (string $attribute, mixed $value, callable $fail): void {
                    $account = Account::query()->postable()->whereKey(self::asId($value))->first();

                    if ($account === null) {
                        $fail('Choose an account that accepts postings.');

                        return;
                    }

                    if (! in_array($account->type->value, ['expense', 'asset'], strict: true)) {
                        $fail('A purchase account must be an expense or asset account.');
                    }
                },
            ],
            'inventory_account_id' => [
                'nullable',
                'uuid',
                $this->accountRule('asset', 'An inventory account must be an asset account.'),
            ],

            'sales_tax_id' => ['nullable', 'uuid', $this->taxRule()],
            'purchase_tax_id' => ['nullable', 'uuid', $this->taxRule()],

            'is_tracked' => ['nullable', 'boolean'],
            'is_sold' => ['nullable', 'boolean'],
            'is_purchased' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The two rules that need to see more than one field at once.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $tracked = $this->boolean('is_tracked');

            // A service has no stock, so tracking one is a contradiction
            // rather than a preference. A CHECK constraint refuses it too.
            if ($tracked && $this->input('kind') !== ItemKind::Goods->value) {
                $validator->errors()->add(
                    'is_tracked',
                    'Only goods can be tracked — a service has no stock to count.',
                );
            }

            // Tracked stock has to land somewhere on the balance sheet.
            if ($tracked && ! is_string($this->input('inventory_account_id'))) {
                $validator->errors()->add(
                    'inventory_account_id',
                    'Tracked goods need an inventory account, or their value has nowhere to sit.',
                );
            }

            // An item that is neither sold nor purchased cannot appear on any
            // document, so it is a row nobody could use.
            if (! $this->boolean('is_sold') && ! $this->boolean('is_purchased')) {
                $validator->errors()->add(
                    'is_sold',
                    'An item has to be sold, purchased, or both — otherwise it cannot go on a document.',
                );
            }
        });
    }

    private function accountRule(string $type, string $message): \Closure
    {
        return function (string $attribute, mixed $value, callable $fail) use ($type, $message): void {
            $account = Account::query()->postable()->whereKey(self::asId($value))->first();

            if ($account === null) {
                $fail('Choose an account that accepts postings.');

                return;
            }

            if ($account->type->value !== $type) {
                $fail($message);
            }
        };
    }

    private function taxRule(): \Closure
    {
        return static function (string $attribute, mixed $value, callable $fail): void {
            if (Tax::query()->usable()->whereKey(self::asId($value))->first() === null) {
                $fail('Choose a tax that is active.');
            }
        };
    }

    protected function prepareForValidation(): void
    {
        $currency = $this->input('currency');

        $this->merge([
            'currency' => is_string($currency) && trim($currency) !== ''
                ? mb_strtoupper(trim($currency))
                : null,
        ]);

        foreach (['sku', 'sale_price', 'purchase_price'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
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
