<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Services\LotAllocator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A small drink-and-snack catalogue so a fresh install has something to sell
 * and the dashboard, reports and best-sellers screens have data to render.
 *
 * Written with firstOrCreate so re-running the seeder is safe: an
 * administrator who has since re-priced or renamed a seeded product keeps
 * their edit.
 */
class CatalogueSeeder extends Seeder
{
    /**
     * @var array<string, array{price: float, cost: float, stock: int, threshold?: int}>
     */
    private const CATALOGUE = [
        'Coffee' => [
            'Flat White' => ['price' => 4.50, 'cost' => 1.80, 'stock' => 40, 'threshold' => 10],
            'Latte' => ['price' => 4.80, 'cost' => 1.95, 'stock' => 35, 'threshold' => 10],
            'Americano' => ['price' => 3.80, 'cost' => 1.40, 'stock' => 30, 'threshold' => 8],
            'Cappuccino' => ['price' => 4.60, 'cost' => 1.85, 'stock' => 4, 'threshold' => 10],
        ],
        'Tea' => [
            'English Breakfast' => ['price' => 3.20, 'cost' => 1.10, 'stock' => 25, 'threshold' => 8],
            'Green Tea' => ['price' => 3.00, 'cost' => 1.05, 'stock' => 20, 'threshold' => 8],
        ],
        'Cold Drinks' => [
            'Iced Latte' => ['price' => 5.20, 'cost' => 2.10, 'stock' => 18, 'threshold' => 6],
            'Orange Juice' => ['price' => 4.00, 'cost' => 1.70, 'stock' => 12, 'threshold' => 6],
        ],
        'Snacks' => [
            'Croissant' => ['price' => 3.20, 'cost' => 1.10, 'stock' => 15, 'threshold' => 6],
            'Blueberry Muffin' => ['price' => 3.50, 'cost' => 1.25, 'stock' => 0, 'threshold' => 6],
            'Dark Chocolate Bar' => ['price' => 2.80, 'cost' => 1.20, 'stock' => 24, 'threshold' => 8],
        ],
    ];

    /**
     * Perishable goods, so a fresh install demonstrates batch tracking and
     * FEFO rather than leaving the feature undiscoverable.
     *
     * @var array<string, array{name: string, price: float, cost: float, batches: array<int, array{code: string, quantity: int, days: int}>}>
     */
    private const PERISHABLE = [
        'Fresh' => [
            [
                'name' => 'Milk',
                'price' => 2.20,
                'cost' => 1.30,
                'batches' => [
                    // Spread across the 15-day warning window, so the till
                    // shows a warning for each and FEFO has a real choice.
                    ['code' => 'MILK-B3', 'quantity' => 9, 'days' => 2],
                    ['code' => 'MILK-B1', 'quantity' => 12, 'days' => 5],
                    ['code' => 'MILK-B2', 'quantity' => 8, 'days' => 12],
                    // Beyond the window, so the boundary is visible too.
                    ['code' => 'MILK-B4', 'quantity' => 6, 'days' => 21],
                ],
            ],
            [
                'name' => 'Plain Bagel',
                'price' => 2.60,
                'cost' => 1.00,
                'batches' => [
                    ['code' => 'BAG-A', 'quantity' => 8, 'days' => 1],
                ],
            ],
            [
                'name' => 'Greek Yogurt',
                'price' => 3.40,
                'cost' => 1.90,
                'batches' => [
                    ['code' => 'YOG-A', 'quantity' => 10, 'days' => 11],
                ],
            ],
            [
                'name' => 'Chicken Wrap',
                'price' => 6.50,
                'cost' => 3.10,
                'batches' => [
                    ['code' => 'WRP-A', 'quantity' => 6, 'days' => 2],
                    ['code' => 'WRP-B', 'quantity' => 6, 'days' => 4],
                ],
            ],
            [
                // Only an expired batch, so the terminal refuses to sell it
                // and the admin override path is demonstrable.
                'name' => 'Day-old Danish',
                'price' => 1.50,
                'cost' => 0.60,
                'batches' => [
                    ['code' => 'DAN-OLD', 'quantity' => 5, 'days' => -2],
                ],
            ],
        ],
    ];

    /**
     * Catalogue products that are perishable and should therefore be tracked
     * batch by batch, with the batches to seed for each.
     *
     * Keyed by product name. A batch given `days` < 0 has already expired,
     * which is what demonstrates the block on the terminal.
     *
     * @var array<string, array<int, array{code: string, quantity: int, days: int}>>
     */
    private const TRACKED_CATALOGUE = [
        'Croissant' => [
            ['code' => 'CRO-A', 'quantity' => 10, 'days' => 1],
            ['code' => 'CRO-B', 'quantity' => 10, 'days' => 2],
        ],
        'Blueberry Muffin' => [
            ['code' => 'MUF-A', 'quantity' => 12, 'days' => 4],
        ],
        'Orange Juice' => [
            ['code' => 'OJ-A', 'quantity' => 14, 'days' => 9],
        ],
        'Dark Chocolate Bar' => [
            // Well beyond the 15-day window, so the contrast is visible.
            ['code' => 'DCB-A', 'quantity' => 20, 'days' => 45],
        ],
    ];

    public function run(): void
    {
        foreach (self::CATALOGUE as $categoryName => $products) {
            // The slug is derived the same way StoreCategoryRequest derives it
            // from the name, since the column is NOT NULL and unique.
            $category = Category::firstOrCreate(
                ['name' => $categoryName],
                ['slug' => Str::slug($categoryName)],
            );

            foreach ($products as $name => $spec) {
                // firstOrCreate, not updateOrCreate: re-seeding is a
                // convenience, not a reset. An administrator who has since
                // re-priced, renamed or archived a seeded product must keep
                // that edit, otherwise running db:seed silently reverts the
                // shop's catalogue.
                Product::firstOrCreate(
                    ['sku' => $this->sku($categoryName, $name)],
                    [
                        'category_id' => $category->getKey(),
                        'name' => $name,
                        'price' => $spec['price'],
                        'cost' => $spec['cost'],
                        'stock' => $spec['stock'],
                        'low_stock_threshold' => $spec['threshold'],
                        'is_active' => true,
                    ],
                );
            }
        }

        $this->seedPerishables();
    }

    /**
     * Give the perishable items in the main catalogue an expiry track and a
     * set of batches, so the terminal shows real warnings for shop goods
     * rather than only for the dedicated Fresh category.
     *
     * Stock is moved into the batches through the allocator, which keeps
     * products.stock in step and logs the movement, so the seeded figures stay
     * consistent with the ordinary seeded stock.
     */
    private function trackCatalogueItems(LotAllocator $allocator): void
    {
        foreach (self::TRACKED_CATALOGUE as $name => $batches) {
            $product = Product::where('name', $name)->first();

            if ($product === null) {
                continue;
            }

            if (! $product->tracks_expiry) {
                $product->forceFill([
                    'tracks_expiry' => true,
                    'expiry_warning_days' => Product::DEFAULT_EXPIRY_WARNING_DAYS,
                ])->save();
            }

            // Already has batches: this seeder has run before, and an
            // administrator's stock must not be topped up again.
            if ($product->lots()->exists()) {
                continue;
            }

            // The seeded stock was set directly on the product. Move it into
            // the batches so nothing is counted twice.
            $remaining = (int) $product->stock;
            $product->forceFill(['stock' => 0])->save();

            foreach ($batches as $index => $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $quantity = min($remaining, $batch['quantity']);
                $remaining -= $quantity;

                $allocator->receive(
                    product: $product,
                    code: $batch['code'],
                    quantity: $quantity,
                    expiresAt: today()->addDays($batch['days'])->toDateString(),
                    cost: (float) $product->cost,
                );
            }

            // Anything the batches did not cover stays as undated stock.
            if ($remaining > 0) {
                $allocator->receive(
                    product: $product,
                    code: 'UNDATED',
                    quantity: $remaining,
                    expiresAt: null,
                    cost: (float) $product->cost,
                );
            }
        }
    }

    /**
     * Perishable products, tracked batch by batch.
     *
     * These are created firstOrCreate on sku like everything else, but their
     * batches are only seeded once and never overwritten, so an administrator
     * who has counted, sold or discarded a batch keeps their numbers when the
     * seeder is re-run.
     */
    private function seedPerishables(): void
    {
        $allocator = app(LotAllocator::class);

        $this->trackCatalogueItems($allocator);

        foreach (self::PERISHABLE as $categoryName => $products) {
            $category = Category::firstOrCreate(
                ['name' => $categoryName],
                ['slug' => Str::slug($categoryName)],
            );

            foreach ($products as $spec) {
                $product = Product::firstOrCreate(
                    ['sku' => $this->sku($categoryName, $spec['name'])],
                    [
                        'category_id' => $category->getKey(),
                        'name' => $spec['name'],
                        'price' => $spec['price'],
                        'cost' => $spec['cost'],
                        'stock' => 0,
                        'low_stock_threshold' => 4,
                        'is_active' => true,
                        'tracks_expiry' => true,
                        'expiry_warning_days' => Product::DEFAULT_EXPIRY_WARNING_DAYS,
                    ],
                );

                if (! $product->wasRecentlyCreated) {
                    continue;
                }

                foreach ($spec['batches'] as $batch) {
                    $allocator->receive(
                        product: $product,
                        code: $batch['code'],
                        quantity: $batch['quantity'],
                        expiresAt: today()->addDays($batch['days'])->toDateString(),
                        cost: $spec['cost'],
                    );
                }
            }
        }
    }

    /**
     * A stable, readable SKU derived from the category and product name.
     */
    private function sku(string $category, string $product): string
    {
        $initials = collect(preg_split('/\s+/', $category))
            ->filter()
            ->map(fn (string $word) => mb_substr($word, 0, 1))
            ->implode('');

        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $product) ?? '');

        return substr($initials.'-'.$slug, 0, 64);
    }
}
