<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

/**
 * Demo sales drain stock, which leaves too many products at zero to be a useful
 * demo. Tops up anything that sold out, then deliberately leaves a couple of
 * products just below their threshold so the low-stock alerts have content.
 */
class RestockSeeder extends Seeder
{
    public function run(InventoryService $inventory): void
    {
        // Anything that sold out goes back to a comfortable level.
        Product::query()
            ->where('stock', '<=', 0)
            ->orderBy('id')
            ->each(function (Product $product) use ($inventory) {
                $inventory->setStock(
                    $product,
                    max(10, (int) $product->low_stock_threshold * 3),
                    'Restock after demo sales',
                    null,
                    $this->topUpLot($product),
                );
            });

        // Every eighth product is left one unit below its alert threshold.
        Product::query()
            ->orderBy('id')
            ->get()
            ->values()
            ->filter(fn (Product $product, int $index) => $index % 8 === 3)
            ->each(function (Product $product) use ($inventory) {
                $target = max(0, (int) $product->low_stock_threshold - 1);

                if ((int) $product->stock <= $target) {
                    $inventory->setStock(
                        $product,
                        $target,
                        'Stock count correction',
                        null,
                        $this->topUpLot($product),
                    );
                }
            });
    }

    /**
     * The lot a top-up should land on.
     *
     * Prefer the soonest-expiring lot still in date, so a restock does not
     * quietly become an undated delivery and lose its expiry date. Falls back to
     * null, which makes the service use the general bucket.
     */
    private function topUpLot(Product $product): ?ProductBatch
    {
        $dated = $product->batches()
            ->sellable()
            ->whereNotNull('expiry_date')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->first();

        if ($dated) {
            return $dated;
        }

        return $product->batches()->whereNull('expiry_date')->orderBy('id')->first();
    }
}
