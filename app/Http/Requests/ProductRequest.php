<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
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
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cost_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:1000000'],
            'unit' => ['required', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],

            // Required when creating: the opening stock becomes a delivery lot,
            // and a lot with no date can never be flagged as expiring. The edit
            // form does not send it, because a product can hold several lots.
            'expiry_date' => $this->expiryRules(),

            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ((float) $this->input('selling_price') < (float) $this->input('cost_price')) {
                $validator->errors()->add(
                    'selling_price',
                    'The selling price is below the cost price. Please confirm this is intended.',
                );
            }

            // A new upload wins over the remove checkbox, so warn instead of
            // silently discarding the file the user just picked.
            if ($this->hasFile('image') && $this->boolean('remove_image')) {
                $validator->errors()->add(
                    'image',
                    'Tick "remove image" without choosing a new file, or choose a new file to replace the current one.',
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'category_id' => 'category',
            'expiry_date' => 'expiry date',
            'image' => 'product image',
            'remove_image' => 'remove image',
        ];
    }

    /**
     * Whether this request is creating a product rather than editing one.
     */
    public function isCreating(): bool
    {
        return $this->route('product') === null;
    }

    /**
     * The expiry date is mandatory on create and absent on edit, so the rule
     * relaxes for the edit case rather than failing a field the form never shows.
     *
     * @return list<mixed>
     */
    public function expiryRules(): array
    {
        return $this->isCreating()
            ? ['required', 'date', 'after_or_equal:today']
            : ['nullable', 'date', 'after_or_equal:today'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'category_id' => ($categoryId = $this->input('category_id')) !== '' ? $categoryId : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
