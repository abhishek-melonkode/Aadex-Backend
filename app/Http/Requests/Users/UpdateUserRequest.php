<?php

namespace App\Http\Requests\Users;

use App\Domain\Identity\Support\RoleHierarchy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:20'],

            // Email and password are deliberately not editable here. Changing
            // someone else's login is a separate, auditable action, and a user
            // changes their own via /auth/change-password.
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'role' => ['sometimes', 'string', Rule::in(RoleHierarchy::assignableBy($this->user()))],
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'You can only assign a role below your own.',
        ];
    }
}
