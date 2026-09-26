<?php

namespace App\Http\Requests\Pos;

use App\Enums\Permission;
use App\Models\Setting;
use App\Services\Cart;
use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::PosCreateOrder->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::in(['cash', 'card', 'digital'])],
            'amount_paid' => ['required', 'numeric', 'min:0'],
            'reference_no' => ['nullable', 'string', 'max:64'],
            'walkin_customer_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Choose how the customer is paying.',
        ];
    }

    /**
     * Card and digital payments are settled by the provider, so the amount
     * tendered is the amount due. Cash may exceed it, and the difference is
     * the change. A digital payment without its reference number cannot be
     * reconciled later, so it is required up front.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $method = (string) $this->input('payment_method');
            $taxRate = Setting::taxRate();
            $due = app(Cart::class)->total($taxRate);

            if ($due === 0) {
                $validator->errors()->add('payment_method', 'There is nothing to charge for.');

                return;
            }

            $paid = Money::toCents($this->input('amount_paid'));

            if ($method === 'cash') {
                if ($paid < $due) {
                    $validator->errors()->add(
                        'amount_paid',
                        'Cash received is short by '.Money::format(($due - $paid) / 100).'.',
                    );
                }

                return;
            }

            if (abs($paid - $due) > 0) {
                $validator->errors()->add(
                    'amount_paid',
                    'A '.$method.' payment must match the total of '.Money::format($due / 100).'.',
                );
            }

            if ($method === 'digital' && blank($this->input('reference_no'))) {
                $validator->errors()->add('reference_no', 'Enter the e-wallet or terminal reference number.');
            }
        });
    }
}
