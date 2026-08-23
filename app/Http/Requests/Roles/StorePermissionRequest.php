<?php

namespace App\Http\Requests\Roles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `{module}.{action}` is not decoration: UserResource groups the
            // matrix by the part before the dot, and every `permission:`
            // middleware entry is written in this shape.
            'name' => [
                'required', 'string', 'max:120',
                'regex:/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/',
                Rule::unique('permissions', 'name')->where('guard_name', 'web'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Use module.action in lowercase — for example housekeeping.inspect.',
        ];
    }
}
