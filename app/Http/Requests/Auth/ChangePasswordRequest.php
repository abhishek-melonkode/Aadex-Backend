<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8), 'confirmed', 'different:current_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.different' => 'The new password must be different from the current password.',
        ];
    }
}
