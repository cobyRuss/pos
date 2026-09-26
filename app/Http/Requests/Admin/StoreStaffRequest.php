<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreStaffRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Password::min(8)],
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

            // The unique rule is case-sensitive on some collations, so an
            // account for "Sam@x.com" must not be shadowed by "sam@x.com".
            $email = mb_strtolower(trim((string) $this->input('email')));

            if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                $validator->errors()->add('email', 'That email address is already registered.');
            }
        });
    }
}
