<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ReceiveLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::InventoryAdjust->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'expires_at' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Give the delivery or batch a reference.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $product = $this->route('product');

            if (! $product->tracks_expiry) {
                // Refusing here stops a batch being attached to a product that
                // will never allocate from it.
                $validator->errors()->add('code', "[{$product->name}] does not track expiry. Turn that on for the product first.");
            }
        });
    }
}
