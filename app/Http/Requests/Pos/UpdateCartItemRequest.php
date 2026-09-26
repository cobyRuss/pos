<?php

namespace App\Http\Requests\Pos;

use App\Enums\Permission;
use App\Models\Product;
use App\Services\Cart;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
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
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $cart = app(Cart::class);
            $productId = $this->integer('product_id');

            if (! $cart->has($productId)) {
                $validator->errors()->add('product_id', 'That item is no longer in the cart.');

                return;
            }

            $quantity = (int) $this->input('quantity');

            if ($quantity === 0) {
                return;
            }

            $product = Product::find($productId);

            if ($product === null) {
                $validator->errors()->add('product_id', 'That product no longer exists.');

                return;
            }

            // A count that exceeds the shelf is a data-entry slip worth
            // surfacing, not something to silently accept.
            if ($quantity > $product->stock) {
                $validator->errors()->add(
                    'quantity',
                    "Only {$product->stock} of [{$product->name}] in stock.",
                );
            }
        });
    }
}
