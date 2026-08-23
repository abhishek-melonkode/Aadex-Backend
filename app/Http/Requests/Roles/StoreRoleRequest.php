<?php

namespace App\Http\Requests\Roles;

use App\Domain\Identity\Support\RoleHierarchy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $range = RoleHierarchy::assignableLevelRange($this->user());

        return [
            // Same shape as the seeded names so `role:` middleware entries
            // stay readable: lowercase, underscores, no spaces.
            'name' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'name')->where('guard_name', 'web')],
            'description' => ['nullable', 'string', 'max:255'],

            // Strictly below the caller's own rank — creating a peer or a
            // superior would be escalation.
            'level' => ['required', 'integer', "min:{$range['min']}", "max:{$range['max']}"],

            // Checked again in the controller against what the caller holds,
            // so a role can never carry more authority than its author.
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Use lowercase letters, digits and underscores, starting with a letter — for example front_office_manager.',
            'level.min' => 'A new role has to rank below your own.',
        ];
    }
}
