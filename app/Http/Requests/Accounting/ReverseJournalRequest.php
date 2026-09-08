<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Domain\Access\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reversing a posted entry.
 *
 * The date is optional and defaults to today. It is deliberately NOT defaulted
 * to the original entry's date: back-dating a correction changes figures that
 * have already been reported.
 *
 * @see ACCOUNTING_RULES.md §4.15
 */
final class ReverseJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::AccountingReverse->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date'],
            // Not required, but strongly encouraged by the form: in six months
            // the reason is the only thing that explains the pair of entries.
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
