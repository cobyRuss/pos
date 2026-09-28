<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CatalogSeeder extends Seeder
{
    /**
     * A small but realistic catalog: 6 categories, 25 products.
     *
     * Stock is seeded through InventoryService so every product opens with a
     * "stock in" movement, which keeps the movement log and stock levels in sync.
     *
     * 'shelf' is the product's shelf life in days. Perishables carry one and are
     * seeded as a dated delivery lot, so the expiry warnings have something real
     * to show; shelf-stable lines omit it and open an undated lot instead.
     *
     * @var array<string, array{description: string, products: array<int, array{name: string, cost: float, price: float, stock: int, low?: int, unit?: string, shelf?: int}>}>
     */
    private const CATALOG = [
        'Beverages' => [
            'description' => 'Soft drinks, water, juice and hot drinks',
            'products' => [
                ['name' => 'Cola 500ml Can', 'cost' => 0.65, 'price' => 1.50, 'stock' => 120, 'low' => 24],
                ['name' => 'Cola 1.5L Bottle', 'cost' => 1.40, 'price' => 2.80, 'stock' => 64, 'low' => 12],
                ['name' => 'Orange Juice 1L', 'cost' => 1.90, 'price' => 3.50, 'stock' => 40, 'low' => 10, 'shelf' => 21],
                ['name' => 'Mineral Water 500ml', 'cost' => 0.20, 'price' => 0.75, 'stock' => 200, 'low' => 48],
                ['name' => 'Ground Coffee 250g', 'cost' => 4.20, 'price' => 7.99, 'stock' => 18, 'low' => 6, 'shelf' => 180],
            ],
        ],
        'Bakery' => [
            'description' => 'Bread, pastries and cakes baked daily',
            'products' => [
                ['name' => 'White Sandwich Loaf', 'cost' => 1.10, 'price' => 2.50, 'stock' => 45, 'low' => 10, 'shelf' => 4],
                ['name' => 'Wholemeal Loaf', 'cost' => 1.25, 'price' => 2.80, 'stock' => 30, 'low' => 10, 'shelf' => 5],
                ['name' => 'Butter Croissant', 'cost' => 0.80, 'price' => 1.95, 'stock' => 36, 'low' => 12, 'shelf' => 2],
                ['name' => 'Chocolate Muffin', 'cost' => 0.70, 'price' => 1.75, 'stock' => 8, 'low' => 10, 'shelf' => 3],
            ],
        ],
        'Dairy' => [
            'description' => 'Milk, cheese, yoghurt and butter',
            'products' => [
                ['name' => 'Whole Milk 1L', 'cost' => 0.95, 'price' => 1.85, 'stock' => 90, 'low' => 24, 'shelf' => 10],
                ['name' => 'Greek Yoghurt 500g', 'cost' => 1.60, 'price' => 3.25, 'stock' => 26, 'low' => 8, 'shelf' => 14],
                ['name' => 'Cheddar Cheese 250g', 'cost' => 2.80, 'price' => 5.50, 'stock' => 22, 'low' => 8, 'shelf' => 45],
                ['name' => 'Salted Butter 250g', 'cost' => 2.10, 'price' => 4.25, 'stock' => 3, 'low' => 6, 'shelf' => 60],
            ],
        ],
        'Snacks' => [
            'description' => 'Crisps, nuts, chocolate and biscuits',
            'products' => [
                ['name' => 'Salted Crisps 150g', 'cost' => 0.75, 'price' => 1.85, 'stock' => 70, 'low' => 18, 'shelf' => 120],
                ['name' => 'Roasted Peanuts 200g', 'cost' => 1.10, 'price' => 2.60, 'stock' => 40, 'low' => 10, 'shelf' => 150],
                ['name' => 'Milk Chocolate Bar 100g', 'cost' => 1.35, 'price' => 2.95, 'stock' => 55, 'low' => 15, 'shelf' => 270],
                ['name' => 'Digestive Biscuits 300g', 'cost' => 0.90, 'price' => 2.20, 'stock' => 0, 'low' => 10],
            ],
        ],
        'Household' => [
            'description' => 'Cleaning and kitchen supplies',
            'products' => [
                ['name' => 'Dish Soap 500ml', 'cost' => 1.20, 'price' => 2.75, 'stock' => 34, 'low' => 8],
                ['name' => 'Kitchen Roll (2 rolls)', 'cost' => 1.55, 'price' => 3.40, 'stock' => 28, 'low' => 8],
                ['name' => 'Bin Liners 30ct', 'cost' => 2.00, 'price' => 4.50, 'stock' => 16, 'low' => 6],
                ['name' => 'Laundry Powder 1kg', 'cost' => 4.10, 'price' => 7.95, 'stock' => 12, 'low' => 5],
            ],
        ],
        'Produce' => [
            'description' => 'Fresh fruit and vegetables, sold by weight',
            'products' => [
                ['name' => 'Bananas 1kg', 'cost' => 1.10, 'price' => 2.40, 'stock' => 60, 'low' => 15, 'unit' => 'kg', 'shelf' => 6],
                ['name' => 'Red Apples 1kg', 'cost' => 1.60, 'price' => 3.20, 'stock' => 48, 'low' => 12, 'unit' => 'kg', 'shelf' => 30],
                ['name' => 'Tomatoes 1kg', 'cost' => 1.40, 'price' => 3.10, 'stock' => 24, 'low' => 10, 'unit' => 'kg', 'shelf' => 7],
                // Already past its date, so the expired warning has a real example.
                ['name' => 'Baby Spinach 200g', 'cost' => 1.25, 'price' => 2.90, 'stock' => 5, 'low' => 8, 'shelf' => -2],
            ],
        ],
    ];

    public function run(InventoryService $inventory): void
    {
        foreach (self::CATALOG as $categoryName => $definition) {
            $category = Category::updateOrCreate(
                ['name' => $categoryName],
                ['description' => $definition['description'], 'is_active' => true],
            );

            foreach ($definition['products'] as $row) {
                $product = Product::updateOrCreate(
                    ['name' => $row['name']],
                    [
                        'category_id' => $category->getKey(),
                        'description' => null,
                        'cost_price' => $row['cost'],
                        'selling_price' => $row['price'],
                        'stock' => 0,
                        'low_stock_threshold' => $row['low'] ?? 5,
                        'unit' => $row['unit'] ?? 'pcs',
                        'is_active' => true,
                    ],
                );

                if ((int) $product->stock !== (int) $row['stock']) {
                    DB::transaction(function () use ($inventory, $product, $row) {
                        // A shelf life opens a dated delivery lot; without one the
                        // units land in the undated general bucket.
                        $newBatch = isset($row['shelf'])
                            ? ['expiry_date' => now()->addDays((int) $row['shelf'])->toDateString()]
                            : [];

                        $inventory->setStock(
                            $product,
                            (int) $row['stock'],
                            'Opening stock',
                            null,
                            null,
                            $newBatch,
                        );
                    });
                }
            }
        }
    }
}
