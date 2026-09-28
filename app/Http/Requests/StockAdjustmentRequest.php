<?php

namespace App\Http\Requests;

use App\Enums\InventoryMovementType;
use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StockAdjustmentRequest extends FormRequest
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
        return [
            'type' => ['required', Rule::in([
                InventoryMovementType::StockIn->value,
                InventoryMovementType::StockOut->value,
                InventoryMovementType::Adjustment->value,
            ])],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'new_stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['required', 'string', 'max:255'],

            // Which delivery the units belong to. Empty means "let the service
            // decide": first-expiry-first-out for a removal, the undated
            // general bucket for a stock-in.
            'batch_id' => [
                'nullable',
                'integer',
                Rule::exists('product_batches', 'id')->where('product_id', $this->productId()),
            ],

            // Details for the lot a stock-in opens. Ignored unless batch_id is
            // empty, so a stale form cannot silently re-date an existing lot.
            'batch_no' => ['nullable', 'string', 'max:50'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $type = $this->input('type');

        $validator->after(function (Validator $validator) use ($type) {
            if ($type === InventoryMovementType::Adjustment->value) {
                if ($this->input('new_stock') === null || $this->input('new_stock') === '') {
                    $validator->errors()->add('new_stock', 'Enter the new stock level for an adjustment.');
                }

                return;
            }

            if (! $this->filled('quantity')) {
                $validator->errors()->add('quantity', 'Enter the number of units.');
            }
        });

        // A stock-out must not drive stock below zero.
        $validator->after(function (Validator $validator) {
            if ($this->input('type') !== InventoryMovementType::StockOut->value) {
                return;
            }

            $product = $this->route('product');
            $quantity = (int) $this->input('quantity');

            if (! $product instanceof Product) {
                return;
            }

            // Against a named lot the limit is that lot's own count; otherwise
            // it is the whole sellable total, since FEFO spreads the removal
            // across lots.
            $batch = $this->chosenBatch();

            if ($batch !== null) {
                if ($quantity > (int) $batch->quantity) {
                    $validator->errors()->add('quantity', sprintf(
                        'Cannot remove %d units - lot %s only holds %d.',
                        $quantity,
                        $batch->displayLabel(),
                        (int) $batch->quantity,
                    ));
                }

                return;
            }

            if ($quantity > (int) $product->stock) {
                $validator->errors()->add('quantity', sprintf(
                    'Cannot remove %d units - only %d in stock.',
                    $quantity,
                    $product->stock,
                ));
            }
        });

        // Receiving perishable stock without a date would create a lot that can
        // never be flagged as expiring, so the date is required up front.
        $validator->after(function (Validator $validator) {
            if ($this->input('type') !== InventoryMovementType::StockIn->value) {
                return;
            }

            // Adding to a lot that already exists: its date is already recorded.
            if ($this->filled('batch_id') || $this->filled('expiry_date')) {
                return;
            }

            $validator->errors()->add(
                'expiry_date',
                'Enter the expiry date for this delivery, or pick an existing lot to add to.',
            );
        });
    }

    public function attributes(): array
    {
        return [
            'new_stock' => 'new stock level',
            'batch_id' => 'batch',
            'batch_no' => 'batch number',
            'expiry_date' => 'expiry date',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'batch_id' => ($id = $this->input('batch_id')) !== '' && $id !== null ? $id : null,
            'batch_no' => ($no = trim((string) $this->input('batch_no'))) !== '' ? $no : null,
            'expiry_date' => ($date = $this->input('expiry_date')) !== '' ? $date : null,
        ]);
    }

    /**
     * Details of the lot a stock-in should open, or an empty array when the
     * service should pick the target itself.
     *
     * Only an explicitly chosen existing lot suppresses lot creation. Typing a
     * batch number means "record a new delivery", so it must still carry its
     * date through.
     *
     * @return array{batch_no?: ?string, expiry_date?: ?string}
     */
    public function newBatch(): array
    {
        if ($this->filled('batch_id')) {
            return [];
        }

        return array_filter([
            'batch_no' => $this->input('batch_no'),
            'expiry_date' => $this->input('expiry_date'),
        ], fn ($value) => $value !== null);
    }

    private function chosenBatch(): ?ProductBatch
    {
        $id = $this->input('batch_id');

        if (blank($id)) {
            return null;
        }

        $product = $this->route('product');

        return ProductBatch::where('product_id', $product instanceof Product ? $product->getKey() : 0)
            ->whereKey($id)
            ->first();
    }

    private function productId(): int|string|null
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product->getKey() : null;
    }
}
