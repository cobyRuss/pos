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
        // Anything that sold out goes back to the level the demand model says it
        // should be sitting at, not to an arbitrary multiple of its alert
        // threshold. The threshold is a reorder trigger, not a target.
        Product::query()
            ->where('stock', '<=', 0)
            ->orderBy('id')
            ->each(function (Product $product) use ($inventory) {
                [$lot, $newBatch] = $this->topUpLot($product);

                $inventory->setStock(
                    $product,
                    CatalogSeeder::targetStockFor($product->name),
                    'Restock after demo sales',
                    null,
                    $lot,
                    $newBatch,
                );
            });

        // Every eighth product is left just below its reorder point, so the
        // low-stock alerts and the order sheet have real content to show.
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
                        ...$this->topUpLot($product),
                    );
                }
            });
    }

    /**
     * The lot a top-up should land on, plus the date to open one with.
     *
     * Returns an existing lot when there is one to add to, and otherwise asks
     * for a new dated lot at the product's researched shelf life.
     *
     * The second half matters more than it looks. `InventoryService` creates an
     * *undated* general bucket when a stock adjustment supplies no expiry date,
     * which is the right default for a real stock count - a unit whose vintage
     * nobody knows genuinely has no date. But the seeder does know the vintage:
     * it is the researched shelf life. Letting a restock fall through to the
     * undated bucket would mean chips sitting on the shelf that can never be
     * flagged as expiring, quietly disabling the FEFO and expiry machinery for
     * exactly the products most likely to go stale.
     *
     * @return array{0: ProductBatch|null, 1: array<string, mixed>}
     */
    private function topUpLot(Product $product): array
    {
        $dated = $product->batches()
            ->sellable()
            ->whereNotNull('expiry_date')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->first();

        if ($dated) {
            return [$dated, []];
        }

        // A lot that still exists but is empty, or one in the undated bucket,
        // can absorb the stock. Anything else opens a properly dated lot.
        $existing = $product->batches()->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('id')->first();

        if ($existing) {
            return [$existing, []];
        }

        return [null, ['expiry_date' => now()->addDays(CatalogSeeder::shelfLifeFor($product->name))->toDateString()]];
    }
}
