<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Models\Account;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A hand-written journal entry.
 *
 * Shape only. Whether it balances, whether the period is open, whether the
 * accounts accept postings — all of that belongs to the ledger and is
 * enforced by {@see PostJournalEntry}. This
 * class exists so the ledger receives well-formed input, and so the user gets
 * their errors attached to the right fields.
 *
 * The one accounting rule asserted here is the balance check, because it is
 * the one the user can fix without leaving the form and it reads far better
 * next to the totals than as a flash message.
 *
 * @see ACCOUNTING_RULES.md §4, I1
 */
final class StoreJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::AccountingPost->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:255'],
            'post_to_closed_period' => ['nullable', 'boolean'],

            // Two lines minimum. One line cannot balance, so accepting one
            // would only produce an error a step later.
            'lines' => ['required', 'array', 'min:2', 'max:200'],

            'lines.*.account_id' => [
                'required',
                'uuid',
                /*
                 * Existence is checked through the Eloquent model, so the
                 * organisation scope applies: an account id from another
                 * organisation is "invalid", not "forbidden", and the error
                 * reveals nothing about whether it exists elsewhere.
                 */
                function (string $attribute, mixed $value, callable $fail): void {
                    $exists = Account::query()->postable()->whereKey($value)->exists();

                    if (! $exists) {
                        $fail('Choose an account that accepts postings.');
                    }
                },
            ],

            'lines.*.side' => ['required', 'in:debit,credit'],

            /*
             * A decimal STRING, never a float. `numeric` would accept
             * scientific notation and `decimal:0,4` accepts what the column
             * holds, so the value reaches the ledger with the precision the
             * user typed.
             */
            'lines.*.amount' => ['required', 'string', 'decimal:0,4'],

            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.min' => 'A journal entry needs at least two lines: something debited and something credited.',
            'lines.*.amount.decimal' => 'Enter an amount with up to four decimal places.',
            'lines.*.side.in' => 'Each line is either a debit or a credit.',
        ];
    }

    /**
     * The balance check, and the two shape rules that go with it.
     *
     * Run after the field rules so a malformed amount produces one clear
     * error rather than a confusing "does not balance" alongside it.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var list<array{account_id?: string, side?: string, amount?: string}> $lines */
            $lines = $this->input('lines', []);

            $debits = BigDecimal::zero();
            $credits = BigDecimal::zero();

            foreach ($lines as $index => $line) {
                try {
                    $amount = BigDecimal::of($line['amount'] ?? '0');
                } catch (MathException) {
                    // Already reported by the field rule.
                    return;
                }

                if (! $amount->isPositive()) {
                    // A zero or negative line. A negative debit is a credit,
                    // and allowing both spellings makes every report
                    // ambiguous.
                    $validator->errors()->add(
                        "lines.{$index}.amount",
                        'Enter an amount greater than zero. To record the other side, switch it to '.
                        (($line['side'] ?? 'debit') === 'debit' ? 'a credit.' : 'a debit.'),
                    );

                    continue;
                }

                ($line['side'] ?? null) === 'debit'
                    ? $debits = $debits->plus($amount)
                    : $credits = $credits->plus($amount);
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $debits->isEqualTo($credits)) {
                $validator->errors()->add('lines', sprintf(
                    'This entry does not balance. Debits total %s and credits total %s, '.
                    'a difference of %s.',
                    (string) $debits->toScale(4),
                    (string) $credits->toScale(4),
                    (string) $debits->minus($credits)->abs()->toScale(4),
                ));
            }
        });
    }

    /**
     * Amounts arrive as strings whatever the client sent.
     *
     * A JSON number is a double, and a double cannot hold 0.1 exactly. If one
     * ever reaches here, it is stringified before validation rather than
     * silently losing a fraction of a rupee.
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
                    if (! is_array($line) || ! isset($line['amount'])) {
                        return $line;
                    }

                    $line['amount'] = is_string($line['amount'])
                        ? trim($line['amount'])
                        : (is_scalar($line['amount']) ? (string) $line['amount'] : '');

                    return $line;
                },
                $lines,
            ),
        ]);
    }
}
