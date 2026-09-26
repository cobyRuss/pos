<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(3, true));

        return [
            'category_id' => Category::factory(),
            'name' => $name,
            'sku' => strtoupper(Str::random(10)),
            'barcode' => null,
            'description' => fake()->optional()->sentence(),
            'price' => fake()->randomFloat(2, 1, 200),
            'cost' => fake()->randomFloat(2, 0.5, 150),
            'stock' => fake()->numberBetween(0, 100),
            'low_stock_threshold' => 5,
            'image' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => ['stock' => 0]);
    }

    public function lowStock(): static
    {
        return $this->state(fn () => ['stock' => 2, 'low_stock_threshold' => 5]);
    }

    /**
     * A valid EAN-13 barcode.
     */
    public function withBarcode(?string $barcode = null): static
    {
        return $this->state(fn () => ['barcode' => $barcode ?? $this->ean13()]);
    }

    protected function ean13(): string
    {
        $digits = array_map('intval', str_split(fake()->unique()->numerify('#########')));

        $sum = 0;

        foreach ($digits as $index => $digit) {
            $sum += $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return implode('').$digits.((10 - ($sum % 10)) % 10);
    }
}
