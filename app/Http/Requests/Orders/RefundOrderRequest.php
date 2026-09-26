<?php

namespace App\Http\Requests\Orders;

use App\Enums\Permission;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\RefundItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RefundOrderRequest extends FormRequest
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

            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.condition' => ['required', Rule::in([
                RefundItem::CONDITION_RESTOCKABLE,
                RefundItem::CONDITION_DAMAGED,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Select at least one item to return.',
            'items.min' => 'Select at least one item to return.',
            'items.*.condition.required' => 'Choose whether each returned item is restockable or damaged.',
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
                    'items' => "Order [{$order->order_number}] was cancelled, so there is nothing to refund.",
                ]);
            }

            // Scoped to this order: a line from someone else's sale must never
            // be refundable here, or a forged id could drain an unrelated order.
            $items = OrderItem::query()
                ->where('order_id', $order->getKey())
                ->whereIn('id', array_column($this->input('items', []), 'order_item_id'))
                ->get()
                ->keyBy('id');

            if ($items->count() !== count($this->input('items', []))) {
                $validator->errors()->add('items', 'One of the selected items is no longer part of this order.');

                return;
            }

            foreach ($this->input('items', []) as $index => $line) {
                $item = $items->get((int) $line['order_item_id']);
                $quantity = (int) $line['quantity'];

                if ($quantity > $item->returnable_quantity) {
                    $validator->errors()->add(
                        "items.{$index}.quantity",
                        sprintf(
                            'Only %d of [%s] can still be returned.',
                            $item->returnable_quantity,
                            $item->product_name,
                        ),
                    );
                }
            }
        });
    }
}
