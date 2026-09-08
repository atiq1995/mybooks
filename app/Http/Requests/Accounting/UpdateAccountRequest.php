<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Http\Controllers\Accounting\ChartOfAccountsController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing an account.
 *
 * The code is absent on purpose. It is what an account is called in every
 * report, spreadsheet and conversation about these books, and renumbering it
 * silently rewrites the meaning of every historical document that quoted it.
 * A new account plus an archive of the old one is the honest move.
 *
 * Type and normal balance are accepted here but only applied by the
 * controller while the account has never posted — see
 * {@see ChartOfAccountsController::update()}.
 *
 * @see ACCOUNTING_RULES.md §3
 */
final class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::AccountingManageAccounts->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],

            'type' => ['required', Rule::in(AccountType::values())],
            'normal_balance' => ['required', 'in:debit,credit'],

            'parent_id' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    $parent = Account::query()->whereKey($value)->first();

                    if ($parent === null) {
                        $fail('Choose a heading that exists.');

                        return;
                    }

                    if (! $parent->is_header) {
                        $fail("{$parent->code} {$parent->name} is not a heading, so it cannot group accounts.");
                    }

                    /*
                     * An account cannot be its own parent. A deeper cycle is
                     * refused by the same check applied at each level, since a
                     * heading's own parent was validated when it was set.
                     */
                    $editing = $this->route('account');

                    if ($editing instanceof Account && $parent->id === $editing->id) {
                        $fail('An account cannot be filed under itself.');
                    }
                },
            ],
        ];
    }
}
