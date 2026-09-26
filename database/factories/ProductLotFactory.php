<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductLot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductLot>
 */
class ProductLotFactory extends Factory
{
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 20);

        return [
            'product_id' => Product::factory(),
            'code' => Str::upper(Str::random(6)),
            'expires_at' => fake()->optional(0.7)->dateTimeBetween('now', '+6 months'),
            'quantity' => $quantity,
            'cost' => fake()->randomFloat(2, 0.5, 20),
            'received_at' => today()->subDays(fake()->numberBetween(0, 20)),
            'notes' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => today()->subDays(fake()->numberBetween(1, 30)),
            'quantity' => fake()->numberBetween(1, 10),
        ]);
    }

    public function expiringIn(int $days): static
    {
        return $this->state(fn () => ['expires_at' => today()->addDays($days)]);
    }

    public function noExpiry(): static
    {
        return $this->state(fn () => ['expires_at' => null]);
    }

    public function empty(): static
    {
        return $this->state(fn () => ['quantity' => 0]);
    }
}
