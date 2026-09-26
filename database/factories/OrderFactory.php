<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_number' => 'POS-'.now()->format('ymd').'-'.Str::upper(Str::random(4)),
            'user_id' => User::factory(),
            'subtotal' => 0,
            'discount_type' => 'none',
            'discount_value' => 0,
            'discount_amount' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'refunded_total' => 0,
            'payment_method' => 'cash',
            'reference_no' => null,
            'amount_paid' => 0,
            'change_amount' => 0,
            'walkin_customer_name' => null,
            'notes' => null,
            'status' => Order::STATUS_COMPLETED,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => Order::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => 'Test cancellation',
        ]);
    }

    /**
     * A settled cash sale, with change given back.
     */
    public function cash(float $total = 20.0, ?float $tendered = null): static
    {
        return $this->state(fn () => [
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => $tendered ?? $total,
            'change_amount' => round(($tendered ?? $total) - $total, 2),
        ]);
    }
}
