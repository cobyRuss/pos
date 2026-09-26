<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'user_id' => User::factory(),
            'total_amount' => 0,
            'method' => 'cash',
            'reference_no' => null,
            'reason' => null,
        ];
    }
}
