<?php

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\RefundItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RefundItem>
 */
class RefundItemFactory extends Factory
{
    public function definition(): array
    {
        $unitPrice = fake()->randomFloat(2, 1, 50);

        return [
            'refund_id' => Refund::factory(),
            'order_item_id' => OrderItem::factory(),
            'product_id' => Product::factory(),
            'product_name' => fake()->words(2, true),
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'amount' => $unitPrice,
            'condition' => RefundItem::CONDITION_RESTOCKABLE,
            'restocked' => true,
        ];
    }

    public function damaged(): static
    {
        return $this->state(fn () => [
            'condition' => RefundItem::CONDITION_DAMAGED,
            'restocked' => false,
        ]);
    }
}
