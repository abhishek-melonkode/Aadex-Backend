<?php

namespace App\Http\Requests\Users;

use App\Domain\Identity\Support\RoleHierarchy;
use App\Domain\Identity\Support\UserDirectory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],

            // Restricted to what the caller outranks, so the escalation check
            // is a validation error the client can act on rather than a 403
            // from deep inside the controller.
            'role' => ['required', 'string', Rule::in(RoleHierarchy::assignableBy($this->user()))],

            // Must be a hotel the caller can actually reach. A Hotel Admin's
            // value is ignored anyway — they always create in their own hotel
            // — but a Chain Admin picks which of their properties and a Super
            // Admin picks any, so an out-of-reach id is rejected rather than
            // quietly swapped, which would hide the mistake.
            'hotel_id' => [
                'nullable', 'integer', 'exists:hotels,id',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! UserDirectory::canPlaceUserIn($this->user(), (int) $value)) {
                        $fail('You cannot create an account in that hotel.');
                    }
                },
            ],

            // Optional starting permission matrix. Each name must exist and
            // must be one the caller themselves holds — checked in the
            // controller, where the actor's own set is available.
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'You can only create accounts with a role below your own.',
        ];
    }
}
