<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\Setting;
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
            'gcash_reference' => ['nullable', 'string', 'max:60'],

            // The senior citizen / PWD discount is claimed at the counter and
            // only becomes a discount if the name and ID are supplied. There is
            // deliberately no percentage field here: the rate is a store
            // setting, so a cashier cannot decide how much to give away.
            'scpwd' => ['nullable', 'boolean'],
            'scpwd_name' => ['nullable', 'required_if:scpwd,1', 'string', 'max:150'],
            'scpwd_id_type' => ['nullable', 'required_if:scpwd,1', Rule::in(Setting::scpwdIdTypes())],
            'scpwd_id_number' => ['nullable', 'required_if:scpwd,1', 'string', 'max:60'],
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

            // The amount owed is the bill after any statutory discount. Checking
            // it against the undiscounted total would reject a correct payment
            // for a senior citizen.
            $due = $this->amountDue();
            $paid = (float) $this->input('paid_amount');

            if ($paid + 0.001 < $due) {
                $validator->errors()->add(
                    'paid_amount',
                    sprintf('Amount paid is short by %s.', number_format($due - $paid, 2)),
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

    /**
     * What the senior citizen / PWD claim takes off the bill.
     *
     * The rate is a store setting rather than anything the request carries, so
     * the amount is decided on the server from the cart the cashier actually
     * rang up. A client that asks for a bigger discount is simply ignored.
     */
    public function discountAmount(): float
    {
        if (! $this->boolean('scpwd')) {
            return 0.0;
        }

        $rate = Setting::scpwdDiscountRate();

        if ($rate <= 0) {
            return 0.0;
        }

        $totals = $this->cartTotals();

        if ($totals === null) {
            return 0.0;
        }

        return round($totals['total'] * $rate / 100, 2);
    }

    /**
     * The bill the customer actually owes.
     */
    public function amountDue(): float
    {
        $totals = $this->cartTotals();

        if ($totals === null) {
            return 0.0;
        }

        return round(max(0, $totals['total'] - $this->discountAmount()), 2);
    }

    /**
     * The discount evidence to freeze onto the order, or null when no claim was
     * made. Returns null rather than zeroes so a sale with no claim is
     * distinguishable from a 0% claim.
     *
     * @return array<string, mixed>|null
     */
    public function scpwdClaim(): ?array
    {
        if (! $this->boolean('scpwd') || $this->discountAmount() <= 0) {
            return null;
        }

        return [
            'name' => $this->input('scpwd_name'),
            'id_type' => $this->input('scpwd_id_type'),
            'id_number' => $this->input('scpwd_id_number'),
        ];
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
