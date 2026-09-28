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
        ]);
    }
}
