<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::StaffManage->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Ignores this record so saving without changing the address
                // is not treated as a duplicate.
                Rule::unique(User::class, 'email')->ignore($user->getKey()),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            // Blank means "leave the password alone", so an admin editing a
            // name does not reset someone's credentials by accident.
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'role' => ['required', Rule::in(Role::values())],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'is_active' => 'active status',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('email')) {
                return;
            }

            /** @var User $user */
            $user = $this->route('user');
            $email = mb_strtolower(trim((string) $this->input('email')));

            $clash = User::whereRaw('LOWER(email) = ?', [$email])
                ->whereKeyNot($user->getKey())
                ->exists();

            if ($clash) {
                $validator->errors()->add('email', 'That email address is already registered.');
            }
        });
    }
}
