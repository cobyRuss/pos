<?php

namespace App\Http\Requests\Orders;

use App\Enums\Permission;
use App\Models\Refund;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A whole-order return. The line list is derived from what is still
 * returnable, so the cashier only chooses how the money goes back.
 */
class RefundAllRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::RefundProcess->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
            'method' => ['required', Rule::in(Refund::methods())],
            'reference_no' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.required' => 'Choose how the refund was paid out.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('method') === Refund::METHOD_DIGITAL && blank($this->input('reference_no'))) {
                $validator->errors()->add('reference_no', 'Enter the e-wallet or terminal reference for a digital refund.');
            }

            $order = $this->route('order');

            if ($order->is_cancelled) {
                throw ValidationException::withMessages([
                    'method' => "Order [{$order->order_number}] was cancelled, so there is nothing to refund.",
                ]);
            }
        });
    }
}
