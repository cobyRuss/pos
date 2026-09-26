<?php

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\OrderItemLot;
use App\Models\ProductLot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItemLot>
 */
class OrderItemLotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'lot_id' => ProductLot::factory(),
            'quantity' => 1,
        ];
    }
}
