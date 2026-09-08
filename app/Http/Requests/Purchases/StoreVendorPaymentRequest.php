<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchases;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Purchases\Models\PurchaseDocument;
use App\Domain\Tax\Models\Tax;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Money paid to a vendor.
 *
 * Shape, and the arithmetic the user can fix in the form. Whether a bill has
 * room for the allocation belongs to the Action, which holds the
 * authoritative balance — checking it here as well would be a race, since the
 * balance can change between validating and posting.
 *
 * @see ACCOUNTING_RULES.md §4.7
 */
final class StoreVendorPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::PurchasesRecordPayment->value) ?? false;
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

            'bank_account_id' => [
                'required',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    $account = Account::query()->postable()->whereKey(self::asId($value))->first();

                    if ($account === null) {
                        $fail('Choose an account that accepts postings.');

                        return;
                    }

                    /*
                     * Cash and bank only. Offering the whole chart would let
                     * somebody pay a vendor "out of" an expense account,
                     * which balances and is nonsense.
                     */
                    if (! in_array($account->subtype, ['cash', 'bank'], strict: true)) {
                        $fail("{$account->code} {$account->name} is not a bank or cash account.");
                    }
                },
            ],

            'payment_date' => ['required', 'date'],

            'amount' => ['required', 'string', 'decimal:0,4'],

            /*
             * Withholding is a SHARE of the amount settled, not an addition
             * to it. The bill is settled in full; part of the money goes to
             * the tax authority instead of the vendor.
             */
            'withholding_amount' => ['nullable', 'string', 'decimal:0,4'],
            'withholding_tax_id' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    $tax = Tax::query()
                        ->usable()
                        ->withholding()
                        ->whereKey(self::asId($value))
                        ->first();

                    if ($tax === null) {
                        $fail('Choose a withholding tax.');
                    }
                },
            ],

            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'exchange_rate' => ['nullable', 'string', 'decimal:0,10'],

            'method' => ['nullable', 'in:cash,cheque,bank_transfer,card,online,other'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // Allocation is optional: a pure advance allocates to nothing.
            'allocations' => ['nullable', 'array', 'max:200'],
            'allocations.*.document_id' => [
                'required',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (PurchaseDocument::query()->whereKey(self::asId($value))->first() === null) {
                        $fail('That bill could not be found.');
                    }
                },
            ],
            'allocations.*.amount' => ['nullable', 'string', 'decimal:0,4'],
        ];
    }

    /**
     * The arithmetic the user can fix without leaving the form.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $withheldInput = $this->string('withholding_amount')->toString();

            try {
                $amount = BigDecimal::of($this->string('amount')->toString());
                $withheld = BigDecimal::of($withheldInput === '' ? '0' : $withheldInput);
            } catch (MathException) {
                return;
            }

            if (! $amount->isPositive()) {
                $validator->errors()->add('amount', 'Enter an amount greater than zero.');

                return;
            }

            if ($withheld->isGreaterThan($amount)) {
                $validator->errors()->add(
                    'withholding_amount',
                    'Withholding is a share of the amount settled, so it cannot exceed it.',
                );
            }

            $allocated = BigDecimal::zero();

            foreach ((array) ($this->input('allocations') ?? []) as $allocation) {
                if (! is_array($allocation)) {
                    continue;
                }

                $value = $allocation['amount'] ?? null;

                if (! is_string($value) || $value === '') {
                    continue;
                }

                try {
                    $allocated = $allocated->plus(BigDecimal::of($value));
                } catch (MathException) {
                    return;
                }
            }

            if ($allocated->isGreaterThan($amount)) {
                $validator->errors()->add('allocations', sprintf(
                    'You have allocated %s of a %s payment. Reduce the allocations, or leave '.
                    'the remainder unapplied and it is held as an advance with the vendor.',
                    (string) $allocated->toScale(2),
                    (string) $amount->toScale(2),
                ));
            }
        });
    }

    protected function prepareForValidation(): void
    {
        foreach (['amount', 'withholding_amount', 'exchange_rate'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $trimmed = trim($value);
                $this->merge([$field => $trimmed === '' ? null : $trimmed]);
            } elseif (is_int($value) || is_float($value)) {
                // A JSON number is a double, and a double cannot hold 0.1.
                $this->merge([$field => (string) $value]);
            }
        }

        $currency = $this->input('currency');

        if (is_string($currency) && trim($currency) !== '') {
            $this->merge(['currency' => mb_strtoupper(trim($currency))]);
        }

        $allocations = $this->input('allocations');

        if (is_array($allocations)) {
            $this->merge([
                'allocations' => array_values(array_filter(
                    array_map(
                        static function (mixed $allocation): mixed {
                            if (! is_array($allocation)) {
                                return null;
                            }

                            $amount = $allocation['amount'] ?? null;

                            $allocation['amount'] = is_string($amount)
                                ? trim($amount)
                                : (is_scalar($amount) ? (string) $amount : null);

                            // A blank row is an untouched line in the
                            // allocation table, not an instruction.
                            if ($allocation['amount'] === '' || $allocation['amount'] === '0') {
                                return null;
                            }

                            return $allocation;
                        },
                        $allocations,
                    ),
                    static fn (mixed $allocation): bool => is_array($allocation),
                )),
            ]);
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
