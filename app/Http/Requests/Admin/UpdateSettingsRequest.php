<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            Setting::BUSINESS_NAME => ['required', 'string', 'max:255'],
            Setting::BUSINESS_ADDRESS => ['nullable', 'string', 'max:500'],
            Setting::BUSINESS_PHONE => ['nullable', 'string', 'max:64'],
            Setting::CURRENCY_SYMBOL => ['required', 'string', 'max:8'],
            // Stored as a percentage; a rate above 100% is not a tax rate.
            Setting::TAX_RATE => ['required', 'numeric', 'min:0', 'max:100'],
            Setting::RECEIPT_FOOTER => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            Setting::BUSINESS_NAME => 'store name',
            Setting::BUSINESS_ADDRESS => 'address',
            Setting::BUSINESS_PHONE => 'phone',
            Setting::CURRENCY_SYMBOL => 'currency symbol',
            Setting::TAX_RATE => 'tax rate',
            Setting::RECEIPT_FOOTER => 'receipt footer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // Concatenated rather than written as Setting::TAX_RATE.max,
            // which PHP reads as a constant access on a class named "max".
            Setting::TAX_RATE.'.max' => 'The tax rate cannot be more than 100%.',
            Setting::CURRENCY_SYMBOL.'.max' => 'Keep the currency symbol to a few characters.',
        ];
    }
}
