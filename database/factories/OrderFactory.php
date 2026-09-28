<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds orders directly (bypassing the POS) for report and history tests.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 5, 120);
        $discount = 0.0;
        $tax = round($subtotal * 0.05, 2);
        $total = round($subtotal + $tax, 2);

        return [
            'order_number' => Order::generateOrderNumber(),
            'user_id' => User::factory(),
            'status' => OrderStatus::Completed,
            'subtotal' => $subtotal,
            'discount_type' => DiscountType::Fixed,
            'discount_value' => 0,
            'discount_amount' => $discount,
            'tax_rate' => 5,
            'tax_amount' => $tax,
            'total' => $total,
            'refunded_amount' => 0,
            'paid_amount' => $total,
            'change_amount' => 0,
            'payment_method' => PaymentMethod::Cash,
            'customer_note' => null,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => 'Test cancellation',
        ]);
    }

    public function at(\DateTimeInterface $when): static
    {
        return $this->state(fn () => ['created_at' => $when, 'updated_at' => $when]);
    }
}
