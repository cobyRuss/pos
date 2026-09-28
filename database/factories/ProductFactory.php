<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cost = fake()->randomFloat(2, 0.5, 40);

        return [
            'category_id' => Category::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'cost_price' => $cost,
            'selling_price' => round($cost * fake()->randomFloat(2, 1.2, 2.5), 2),
            'stock' => fake()->numberBetween(10, 100),
            'low_stock_threshold' => 5,
            'unit' => 'pcs',
            'is_active' => true,
        ];
    }

    /**
     * Back the requested stock with a real lot.
     *
     * Stock is the sum of product_batches, and a sale draws first-expiry-first-out
     * from those lots, so a product with a stock figure but no lot would report
     * stock on hand and then refuse to sell any of it. Each factory product
     * therefore gets one undated lot holding the whole quantity.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Product $product) {
            if ((int) $product->stock <= 0) {
                return;
            }

            $product->batches()->create(['quantity' => (int) $product->stock]);
        });
    }

    /**
     * A product holding one lot that expires on the given day.
     */
    public function expiringOn(Carbon $date, ?int $quantity = null): static
    {
        return $this->state(fn () => [
            'stock' => $quantity ?? fake()->numberBetween(5, 40),
        ])->afterCreating(function (Product $product) use ($date) {
            $product->batches()->update(['expiry_date' => $date]);
        });
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => ['stock' => 0]);
    }

    public function lowStock(int $stock = 2): static
    {
        return $this->state(fn () => ['stock' => $stock, 'low_stock_threshold' => 5]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function priced(float $cost, float $price): static
    {
        return $this->state(fn () => ['cost_price' => $cost, 'selling_price' => $price]);
    }
}
