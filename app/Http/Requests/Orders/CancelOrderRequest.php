<?php

namespace App\Http\Requests\Orders;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::OrderCancel->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A cancellation reverses a financial transaction, so it is only
            // meaningful if the log says why it happened.
            'cancel_reason' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cancel_reason.required' => 'Give a reason for the cancellation.',
        ];
    }
}
