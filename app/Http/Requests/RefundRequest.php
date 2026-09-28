<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\RefundReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a refund request from the till.
 *
 * Note what is *not* here: there is no `amount` rule, because there is no amount
 * to validate. The peso figure is worked out by `RefundService` from the order
 * lines the customer actually bought. A refund request can only say "these
 * units, this many", never "this much money".
 *
 * The per-line check here is for the cashier's benefit - it produces a readable
 * error on the row they are looking at. The check that actually protects the
 * store is the same one inside the transaction, holding a row lock.
 */
class RefundRequest extends FormRequest
{
    /**
     * Cashiers refund in the owner's absence, so both roles may do this. The
     * owner is exempt from the daily ceiling; the cashier is not.
     */
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
            'reason_code' => ['required', Rule::in(RefundReason::values())],
            // Required when the reason is the catch-all, and ignored otherwise.
            'reason' => [
                Rule::requiredIf(fn () => $this->wantsReasonDetail()),
                'nullable',
                'string',
                'max:255',
            ],
            'method' => ['required', Rule::in(PaymentMethod::values())],
            'note' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items' => 'refund items',
            'reason' => 'reason detail',
            'reason_code' => 'reason',
            'method' => 'refund method',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $order = $this->route('order');

            if (! $order) {
                return;
            }

            $requested = (array) $this->input('items', []);

            if ($requested === []) {
                $validator->errors()->add('items', 'Select at least one item to refund.');

                return;
            }

            // Scoped to this order, so a line id borrowed from another receipt
            // simply does not resolve and is reported as unrecognised.
            $lines = $order->items()
                ->whereIn('id', array_keys($requested))
                ->get()
                ->keyBy('id');

            foreach ($requested as $orderItemId => $quantity) {
                $line = $lines->get((int) $orderItemId);

                if (! $line) {
                    $validator->errors()->add('items', 'One of the selected items is no longer part of this order.');

                    continue;
                }

                if ($line->refundable_quantity === 0) {
                    $validator->errors()->add("items.{$orderItemId}", sprintf(
                        'All %d unit(s) of "%s" have already been refunded.',
                        $line->quantity,
                        $line->product_name,
                    ));

                    continue;
                }

                if ((int) $quantity > $line->refundable_quantity) {
                    $validator->errors()->add("items.{$orderItemId}", sprintf(
                        'Only %d unit(s) of "%s" remain refundable.',
                        $line->refundable_quantity,
                        $line->product_name,
                    ));
                }
            }
        });
    }

    private function wantsReasonDetail(): bool
    {
        return $this->input('reason_code') === RefundReason::Other->value;
    }

    /**
     * @return array<int, int>
     */
    public function quantities(): array
    {
        $quantities = [];

        foreach ((array) $this->input('items', []) as $orderItemId => $quantity) {
            $quantity = (int) $quantity;

            if ($quantity > 0) {
                $quantities[(int) $orderItemId] = $quantity;
            }
        }

        return $quantities;
    }
}
