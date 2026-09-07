<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Domain\Access\Enums\Permission;
use App\Domain\Access\Enums\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changing what someone may do.
 *
 * The role-hierarchy rule — nobody may grant a role above their own — lives in
 * the controller, because it compares the requested role against the ACTOR's,
 * not against the request.
 */
final class UpdateMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::UsersUpdateRole->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(Role::values())],
        ];
    }
}
