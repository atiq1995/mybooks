<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Theme and density preference.
 *
 * A personal preference, so the only authorisation is being signed in — the
 * `auth` middleware on the route already guarantees that.
 */
final class UpdateAppearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'theme' => ['sometimes', 'required', Rule::in(['light', 'dark', 'system'])],
            'density' => ['sometimes', 'required', Rule::in(['compact', 'comfortable'])],
        ];
    }
}
