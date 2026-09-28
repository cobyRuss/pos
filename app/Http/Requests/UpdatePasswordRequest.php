<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', 'min:8', 'different:current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'The provided current password is incorrect.',
            'password.different' => 'The new password must be different from the current password.',
        ];
    }

    public function attributes(): array
    {
        return [
            'current_password' => 'current password',
        ];
    }

    /**
     * Guards against a user submitting a blank/incorrect "current" password
     * that somehow passed the current_password rule via a stale session.
     *
     * @throws ValidationException
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = $this->user();

            if (! $user || ! Hash::check((string) $this->input('current_password'), $user->password)) {
                $validator->errors()->add('current_password', 'The provided current password is incorrect.');
            }
        });
    }
}
