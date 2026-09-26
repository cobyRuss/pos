<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Models\ProductLot;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLotRequest extends FormRequest
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
        /** @var ProductLot $lot */
        $lot = $this->route('lot');
        $product = $this->route('product');

        return [
            'code' => [
                'required', 'string', 'max:64',
                // A batch code is how a delivery is recognised, so it has to
                // stay unique within the product.
                Rule::unique('product_lots', 'code')
                    ->where('product_id', $product->getKey())
                    ->ignore($lot->getKey()),
            ],
            'quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'expires_at' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var ProductLot $lot */
            $lot = $this->route('lot');

            if ((int) $this->route('product')->getKey() !== (int) $lot->product_id) {
                $validator->errors()->add('code', 'That batch belongs to a different product.');
            }
        });
    }
}
