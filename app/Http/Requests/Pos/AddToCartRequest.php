<?php

namespace App\Http\Requests\Pos;

use App\Enums\Permission;
use App\Models\Product;
use App\Services\Cart;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class AddToCartRequest extends FormRequest
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
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_id.exists' => 'That product no longer exists.',
        ];
    }

    /**
     * Refuse unsellable or unavailable products here rather than letting the
     * cart silently clamp the quantity, so the cashier is told the real reason.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $product = Product::find($this->integer('product_id'));

            if ($product === null) {
                return;
            }

            if (! $product->is_active) {
                $validator->errors()->add('product_id', "[{$product->name}] is not available for sale.");

                return;
            }

            $requested = (int) ($this->input('quantity') ?? 1);
            $cart = app(Cart::class);
            $inCart = $cart->quantityOf((int) $product->getKey());

            // Sellable stock excludes batches past their date, so the till
            // cannot offer a cashier something that will be refused at
            // checkout.
            $available = (int) $product->sellable_stock;

            if ($available <= 0) {
                if ($product->tracks_expiry && $product->has_expired_stock) {
                    $validator->errors()->add('product_id', "[{$product->name}] has only expired stock left.");
                } else {
                    $validator->errors()->add('product_id', "[{$product->name}] is out of stock.");
                }

                return;
            }

            if ($inCart + $requested > $available) {
                $remaining = $available - $inCart;

                $validator->errors()->add(
                    'quantity',
                    $remaining > 0
                        ? "Only {$remaining} more of [{$product->name}] available."
                        : "All {$available} of [{$product->name}] are already in the cart.",
                );
            }
        });
    }
}
