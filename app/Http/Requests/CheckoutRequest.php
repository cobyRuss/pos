<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Services\CartService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CheckoutRequest extends FormRequest
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
            'payment_method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'paid_amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The cart must not be empty and the amount tendered must cover the total.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $totals = $this->cartTotals();

            if ($totals === null || $totals['item_count'] === 0) {
                $validator->errors()->add('items', 'Your cart is empty. Add at least one product before checkout.');

                return;
            }

            $paid = (float) $this->input('paid_amount');

            if ($paid + 0.001 < $totals['total']) {
                $validator->errors()->add(
                    'paid_amount',
                    sprintf('Amount paid is short by %s.', number_format($totals['total'] - $paid, 2)),
                );
            }
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cartTotals(): ?array
    {
        return app(CartService::class)->totals();
    }

    public function messages(): array
    {
        return [
            'paid_amount.min' => 'The amount paid cannot be negative.',
        ];
    }

    /**
     * Best-effort stock pre-check for a friendlier error message.
     */
    public function stockIssues(): array
    {
        $issues = [];

        foreach (app(CartService::class)->items() as $line) {
            if ($line['stock'] !== null && $line['stock'] < $line['quantity']) {
                $issues[] = sprintf(
                    '%s (available: %d, in cart: %d)',
                    $line['name'],
                    $line['stock'],
                    $line['quantity'],
                );
            } elseif (! $line['product_exists'] || ! $line['is_active']) {
                $issues[] = $line['name'].' is no longer available.';
            }
        }

        return $issues;
    }
}
