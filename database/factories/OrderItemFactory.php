<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        $product = Product::factory();
        $unitPrice = fake()->randomFloat(2, 1, 50);

        return [
            'order_id' => Order::factory(),
            'product_id' => $product,
            'product_name' => fake()->words(2, true),
            'sku' => strtoupper(fake()->bothify('??????####')),
            'unit_price' => $unitPrice,
            'unit_cost' => round($unitPrice * 0.6, 2),
            'quantity' => fake()->numberBetween(1, 3),
            'discount_amount' => 0,
            'line_total' => $unitPrice,
        ];
    }
}
