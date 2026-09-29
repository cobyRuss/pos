<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
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
            'store_name' => ['required', 'string', 'max:150'],
            'store_address' => ['nullable', 'string', 'max:255'],
            'store_phone' => ['nullable', 'string', 'max:30'],
            'store_email' => ['nullable', 'email', 'max:255'],
            'currency_symbol' => ['required', 'string', 'max:8'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'receipt_size' => ['required', 'in:80mm,58mm'],
            'receipt_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9-]+$/'],

            // 0 turns the senior citizen / PWD discount off, which is a valid
            // choice for a store that does not honour it.
            'scpwd_discount_rate' => ['required', 'numeric', 'min:0', 'max:100'],

            // BIR / PTCA identity. Every one of these is optional in the schema
            // because a store that has not been issued them yet still has to be
            // able to save its settings - the receipt simply omits the line.
            'store_tin' => ['nullable', 'string', 'max:20', 'regex:/^[0-9-]+$/'],
            'store_vat_status' => ['required', 'in:vat,nonvat,exempt'],
            'bir_permit_no' => ['nullable', 'string', 'max:60'],
            'bir_accreditation_no' => ['nullable', 'string', 'max:60'],
            'bir_machine_no' => ['nullable', 'string', 'max:60'],

            'low_stock_default' => ['required', 'integer', 'min:0', 'max:100000'],
            'refund_review_threshold' => ['required', 'numeric', 'min:0', 'max:1000000'],
            // 0 turns the ceiling off, which is the right default until the owner
            // has a feel for how much a normal return runs to.
            'refund_daily_limit' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'telegram_bot_token' => ['nullable', 'string', 'max:120', 'regex:/^[0-9A-Za-z:_-]+$/'],
            'telegram_chat_id' => ['nullable', 'string', 'max:64', 'regex:/^-?[0-9]+$/'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tax_rate' => 'tax rate',
            'receipt_prefix' => 'receipt number prefix',
            'scpwd_discount_rate' => 'senior citizen / PWD discount',
            'store_tin' => 'TIN',
            'store_vat_status' => 'VAT registration',
            'bir_permit_no' => 'PTCA / DTI permit number',
            'bir_accreditation_no' => 'BIR accreditation number',
            'bir_machine_no' => 'registered machine / terminal number',
            'low_stock_default' => 'default low-stock threshold',
            'refund_review_threshold' => 'refund review threshold',
            'refund_daily_limit' => 'cashier daily refund limit',
            'telegram_bot_token' => 'Telegram bot token',
            'telegram_chat_id' => 'Telegram chat ID',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'store_name' => trim((string) $this->input('store_name')),
            'currency_symbol' => trim((string) $this->input('currency_symbol')),
            // An unticked or blank optional field must arrive as an empty string,
            // not as the literal text a number input submits when emptied.
            'telegram_bot_token' => trim((string) $this->input('telegram_bot_token')),
            'telegram_chat_id' => trim((string) $this->input('telegram_chat_id')),
            // Same reasoning for the BIR fields: a number input emptied by the
            // browser submits an empty string, not null.
            'store_tin' => trim((string) $this->input('store_tin')),
            'bir_permit_no' => trim((string) $this->input('bir_permit_no')),
            'bir_accreditation_no' => trim((string) $this->input('bir_accreditation_no')),
            'bir_machine_no' => trim((string) $this->input('bir_machine_no')),
        ]);
    }
}
