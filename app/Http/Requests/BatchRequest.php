<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or edit one delivery lot of a product.
 */
class BatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $batchId = $this->route('batch')?->getKey();

        return [
            'batch_no' => [
                'nullable',
                'string',
                'max:50',
                // Two lots of the same product must not share a reference, or the
                // movement log becomes ambiguous about which one moved.
                Rule::unique('product_batches', 'batch_no')
                    ->where('product_id', $this->productId())
                    ->ignore($batchId),
            ],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:today'],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'batch_no' => 'batch number',
            'expiry_date' => 'expiry date',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $quantity = (int) $this->input('quantity');
            $isExisting = $this->route('batch') !== null;

            // Editing down a lot's quantity is a stock change, so it has to be a
            // deliberate two-step: remove the units through the adjust screen,
            // which writes the before/after trail, then set the count.
            if ($isExisting && $quantity < (int) $this->route('batch')->quantity) {
                $validator->errors()->add(
                    'quantity',
                    'This lot already holds '.$this->route('batch')->quantity.' units. '
                    .'Remove them with a stock adjustment so the change is logged, then set the level here.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'batch_no' => ($no = trim((string) $this->input('batch_no'))) !== '' ? $no : null,
            'expiry_date' => ($date = $this->input('expiry_date')) !== '' ? $date : null,
        ]);
    }

    private function productId(): int|string|null
    {
        $route = $this->route('product');

        if ($route !== null) {
            return $route->getKey();
        }

        return $this->route('batch')?->product_id;
    }
}
