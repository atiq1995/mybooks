<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Domain\Access\Enums\Permission;
use App\Domain\Access\Enums\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inviting someone into the active organisation.
 *
 * Whether the acting user may grant the REQUESTED role is decided by
 * InviteMember, which compares it against their own — a rule that depends on
 * the actor's role rather than on the request, so it does not belong here.
 */
final class InviteMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::UsersInvite->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:160'],
            'role' => ['required', Rule::in(Role::values())],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Addresses are stored and compared lowercase throughout.
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->string('email')->toString()))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.email' => 'Enter the email address to send the invitation to.',
            'role.in' => 'Choose a role from the list.',
        ];
    }
}
