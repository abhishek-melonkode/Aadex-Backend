<?php

namespace App\Http\Requests\Roles;

use App\Domain\Identity\Support\RoleHierarchy;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $range = RoleHierarchy::assignableLevelRange($this->user());

        return [
            // The name is intentionally immutable. Route middleware refers to
            // roles by name (`role:hotel_admin`), so renaming one at runtime
            // would silently unguard every route mentioning it.
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'level' => ['sometimes', 'integer', "min:{$range['min']}", "max:{$range['max']}"],

            // Turn a role off for assignment without deleting it — useful when
            // retiring one gradually while its holders are moved elsewhere.
            'is_assignable' => ['sometimes', 'boolean'],

            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }

    public function messages(): array
    {
        return [
            'level.min' => 'A role has to rank below your own.',
        ];
    }
}
