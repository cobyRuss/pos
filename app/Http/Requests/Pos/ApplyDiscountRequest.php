<?php

namespace App\Http\Requests\Pos;

use App\Enums\Permission;
use App\Services\Cart;
use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::PosAccess->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'discount_type' => ['required', Rule::in([Cart::DISCOUNT_NONE, Cart::DISCOUNT_FIXED, Cart::DISCOUNT_PERCENT])],
            'discount_value' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'discount_value.required' => 'Enter a discount amount, or choose "No discount".',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = (string) $this->input('discount_type');

            if ($type === Cart::DISCOUNT_NONE) {
                return;
            }

            $value = (float) $this->input('discount_value');

            if ($value <= 0) {
                $validator->errors()->add('discount_value', 'A discount must be greater than zero.');

                return;
            }

            // A percentage above 100 is nonsense; a fixed amount above the
            // basket total would produce a negative bill.
            if ($type === Cart::DISCOUNT_PERCENT && $value > 100) {
                $validator->errors()->add('discount_value', 'A percentage discount cannot exceed 100%.');

                return;
            }

            $cart = app(Cart::class);

            if ($type === Cart::DISCOUNT_FIXED) {
                $subtotal = $cart->subtotal();

                if ($subtotal === 0) {
                    $validator->errors()->add('discount_value', 'There is nothing in the cart to discount.');

                    return;
                }

                if (Money::toCents($value) > $subtotal) {
                    $validator->errors()->add(
                        'discount_value',
                        'A fixed discount cannot exceed the cart total of '.Money::format($subtotal / 100).'.',
                    );
                }
            }
        });
    }
}
