<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdjustStockRequest extends FormRequest
{
    /**
     * Accepted values for the "action" field.
     *
     * - set      : a physical stock count; the new absolute level is given
     * - restock  : goods received from a supplier
     * - write_off: damaged, expired or lost stock that leaves sellable inventory
     */
    public const ACTIONS = ['set', 'restock', 'write_off'];

    public function authorize(): bool
    {
        return $this->user()?->can('inventory.adjust') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(self::ACTIONS)],
            // A "set" count of zero is legitimate (the shelf is empty), but
            // receiving or writing off nothing is always a mistake.
            'quantity' => [
                'required',
                'integer',
                Rule::when($this->input('action') !== 'set', 'min:1'),
                'min:0',
                'max:1000000',
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.in' => 'Choose set, restock or write off.',
            'quantity.min' => 'Enter a quantity of at least 1.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'action' => 'action',
            'quantity' => 'quantity',
        ];
    }

    /**
     * Catch an impossible write-off up front so the admin sees the problem on
     * the field rather than as a flash message. The inventory service re-checks
     * this under a row lock, so this rule is only about the experience.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('action') !== 'write_off') {
                return;
            }

            $product = $this->route('product');

            if ($product && (int) $this->input('quantity') > $product->stock) {
                $validator->errors()->add(
                    'quantity',
                    "Only {$product->stock} in stock; you cannot write off more than you have.",
                );
            }
        });
    }
}
