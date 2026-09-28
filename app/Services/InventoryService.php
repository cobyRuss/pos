<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Single place where stock levels change, so every change is traceable
 * through the inventory_movements log.
 *
 * Stock lives in product_batches - one row per delivery, each with its own
 * expiry date - and products.stock is the cached sum of those lots, written
 * here through Product::recalculateStock(). Two rules govern where units come
 * from:
 *
 *   - an increase with no lot named lands in the undated "general" bucket,
 *     which is what non-perishables and returns use;
 *   - a decrease with no lot named is drawn first-expiry-first-out from every
 *     sellable lot, so the soonest deadline is always sold down first and
 *     expired lots are never touched.
 *
 * A single call can span several lots, so a movement row is written per lot
 * touched. The rows chain together through before_stock/after_stock, so a
 * two-lot sale still reads as one continuous product-level trail.
 *
 * Callers are responsible for wrapping multi-write work in a transaction.
 */
class InventoryService
{
    /**
     * Apply a signed delta to a product's stock and record the movements.
     *
     * @param  int  $quantity  Positive to increase stock, negative to decrease.
     * @param  ProductBatch|null  $batch  Target a specific lot. Omit to let the
     *                                    service pick: FEFO for a decrease, the
     *                                    general bucket for an increase.
     * @param  array{batch_no?: ?string, expiry_date?: ?string, notes?: ?string}  $newBatch
     *                                                                                       Details for a lot to create on a
     *                                                                                       stock-in. Supplying either field opens
     *                                                                                       a new dated lot instead of reusing the
     *                                                                                       general bucket.
     * @return Collection<int, InventoryMovement> One row per lot touched.
     *
     * @throws InsufficientStockException when decreasing below what is sellable.
     */
    public function adjust(
        Product $product,
        int $quantity,
        InventoryMovementType $type,
        ?string $reason = null,
        ?Model $reference = null,
        ?User $user = null,
        ?ProductBatch $batch = null,
        array $newBatch = [],
    ): Collection {
        if ($quantity === 0) {
            throw new \InvalidArgumentException('Inventory adjustment quantity cannot be zero.');
        }

        // Lock the product row so concurrent checkouts cannot oversell, then the
        // lots in a stable order (id is the tiebreak) so two concurrent sales
        // cannot deadlock against each other.
        $product = Product::whereKey($product->getKey())->lockForUpdate()->firstOrFail();
        $batches = $this->lockBatches($product);

        $allocations = $this->allocate($product, $batches, $quantity, $batch, $newBatch);

        $running = (int) $product->stock;
        $movements = new Collection;

        foreach ($allocations as [$target, $units]) {
            $signed = $quantity < 0 ? -$units : $units;

            $target->forceFill(['quantity' => (int) $target->quantity + $signed])->save();

            $rowBefore = $running;
            $running += $signed;

            $movements->push(InventoryMovement::create([
                'product_id' => $product->getKey(),
                'batch_id' => $target->getKey(),
                // Snapshot, so editing or deleting the lot later does not rewrite
                // the date this movement was recorded under.
                'expiry_date' => $target->expiry_date,
                'user_id' => $user?->getAuthIdentifier(),
                'type' => $type,
                'quantity' => $signed,
                'before_stock' => $rowBefore,
                'after_stock' => $running,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]));
        }

        $product->forceFill(['stock' => max(0, $running)])->save();

        return $movements;
    }

    /**
     * Increase stock (stock-in, return restock, cancelled sale).
     *
     * @return Collection<int, InventoryMovement>
     */
    public function increase(
        Product $product,
        int $quantity,
        InventoryMovementType $type,
        ?string $reason = null,
        ?Model $reference = null,
        ?User $user = null,
        ?ProductBatch $batch = null,
        array $newBatch = [],
    ): Collection {
        return $this->adjust($product, abs($quantity), $type, $reason, $reference, $user, $batch, $newBatch);
    }

    /**
     * Decrease stock (sale, manual stock-out).
     *
     * @return Collection<int, InventoryMovement>
     *
     * @throws InsufficientStockException
     */
    public function decrease(
        Product $product,
        int $quantity,
        InventoryMovementType $type,
        ?string $reason = null,
        ?Model $reference = null,
        ?User $user = null,
        ?ProductBatch $batch = null,
    ): Collection {
        return $this->adjust($product, -abs($quantity), $type, $reason, $reference, $user, $batch);
    }

    /**
     * Set stock to an absolute value, logging the difference as an adjustment.
     *
     * An absolute figure has no lot of its own, so the delta lands in the
     * general bucket unless the caller names one.
     *
     * @return Collection<int, InventoryMovement>
     */
    public function setStock(
        Product $product,
        int $newStock,
        string $reason,
        ?User $user = null,
        ?ProductBatch $batch = null,
        array $newBatch = [],
    ): Collection {
        $newStock = max(0, $newStock);

        $product = Product::whereKey($product->getKey())->lockForUpdate()->firstOrFail();
        $current = (int) $product->stock;
        $delta = $newStock - $current;

        if ($delta !== 0) {
            return $this->adjust(
                $product,
                $delta,
                InventoryMovementType::Adjustment,
                $reason,
                null,
                $user,
                $batch,
                $newBatch,
            );
        }

        // A stock count that confirmed the existing level still needs a log row,
        // so write the zero-quantity entry directly rather than tripping the
        // zero-delta guard in adjust().
        $batches = $this->lockBatches($product);
        $target = $batch ?? $this->resolveDepositTarget($product, $batches, $newBatch);

        return collect([InventoryMovement::create([
            'product_id' => $product->getKey(),
            'batch_id' => $target->getKey(),
            'expiry_date' => $target->expiry_date,
            'user_id' => $user?->getAuthIdentifier(),
            'type' => InventoryMovementType::Adjustment,
            'quantity' => 0,
            'before_stock' => $current,
            'after_stock' => $current,
            'reason' => $reason,
        ])]);
    }

    /**
     * Put sold units back on the lots they were taken from.
     *
     * A first-expiry-first-out sale can have drawn from several lots, so the
     * return walks the recorded split in consumption order - soonest expiry
     * first - rather than guessing one lot. Anything that cannot go back where it
     * came from (the lot was deleted, or the line predates the split table)
     * lands in the undated general bucket, which is the honest home for units of
     * unknown vintage.
     *
     * @return Collection<int, InventoryMovement>
     */
    public function returnToLots(
        OrderItem $item,
        int $quantity,
        InventoryMovementType $type,
        string $reason,
        ?Model $reference = null,
        ?User $user = null,
    ): Collection {
        if ($quantity <= 0 || ! $item->product) {
            return new Collection;
        }

        $product = Product::whereKey($item->product->getKey())->lockForUpdate()->firstOrFail();
        $batches = $this->lockBatches($product)->keyBy('id');

        $usages = $item->batchUsages()->where('quantity', '>', 0)->orderBy('id')->get();

        $remaining = $quantity;
        $running = (int) $product->stock;
        $movements = new Collection;

        $record = function (?ProductBatch $target, int $units) use (&$movements, &$running, $product, $type, $reason, $reference, $user) {
            $target->forceFill(['quantity' => (int) $target->quantity + $units])->save();

            $rowBefore = $running;
            $running += $units;

            $movements->push(InventoryMovement::create([
                'product_id' => $product->getKey(),
                'batch_id' => $target->getKey(),
                'expiry_date' => $target->expiry_date,
                'user_id' => $user?->getAuthIdentifier(),
                'type' => $type,
                'quantity' => $units,
                'before_stock' => $rowBefore,
                'after_stock' => $running,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]));
        };

        foreach ($usages as $usage) {
            if ($remaining <= 0) {
                break;
            }

            $give = min($remaining, (int) $usage->quantity);

            if ($give <= 0) {
                continue;
            }

            $target = $usage->batch_id ? $batches->get($usage->batch_id) : null;

            if (! $target) {
                // The lot is gone. Zero the row so it is not retried forever and
                // let the remainder fall through to the general bucket.
                $usage->forceFill(['quantity' => 0])->save();

                continue;
            }

            $usage->forceFill(['quantity' => (int) $usage->quantity - $give])->save();
            $remaining -= $give;

            $record($target, $give);
        }

        if ($remaining > 0) {
            $record($this->resolveDepositTarget($product, $batches->values(), []), $remaining);
        }

        $product->forceFill(['stock' => max(0, $running)])->save();

        return $movements;
    }

    /**
     * The product's lots, locked and already in first-expiry-first-out order.
     *
     * Undated lots sort last: they never expire, so they can wait until every
     * dated lot has been sold down.
     *
     * @return Collection<int, ProductBatch>
     */
    private function lockBatches(Product $product): Collection
    {
        return ProductBatch::where('product_id', $product->getKey())
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Decide which lots absorb the delta, before anything is written.
     *
     * @param  Collection<int, ProductBatch>  $batches  Locked, in FEFO order.
     * @return list<array{0: ProductBatch, 1: int}>
     */
    private function allocate(
        Product $product,
        Collection $batches,
        int $quantity,
        ?ProductBatch $batch,
        array $newBatch,
    ): array {
        $needed = abs($quantity);

        if ($batch !== null) {
            $target = $batches->firstWhere('id', $batch->getKey());

            if (! $target) {
                throw new \InvalidArgumentException('The chosen batch does not belong to this product.');
            }

            // An explicitly named lot is authoritative even when expired - that
            // is how an administrator writes off goods that went past their date.
            if ($quantity < 0 && (int) $target->quantity < $needed) {
                throw new InsufficientStockException(
                    $product,
                    $needed,
                    (int) $target->quantity,
                    (int) $batches->where(fn (ProductBatch $b) => $b->isExpired())->sum('quantity'),
                );
            }

            return [[$target, $needed]];
        }

        if ($quantity < 0) {
            return $this->allocateFefo($product, $batches, $needed);
        }

        return [[$this->resolveDepositTarget($product, $batches, $newBatch), $needed]];
    }

    /**
     * Spread a decrease across lots by earliest expiry date first.
     *
     * @param  Collection<int, ProductBatch>  $batches  Locked, in FEFO order.
     * @return list<array{0: ProductBatch, 1: int}>
     */
    private function allocateFefo(Product $product, Collection $batches, int $needed): array
    {
        $remaining = $needed;
        $allocations = [];

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            if (! $batch->isSellable()) {
                continue;
            }

            $take = min($remaining, (int) $batch->quantity);

            if ($take <= 0) {
                continue;
            }

            $allocations[] = [$batch, $take];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw new InsufficientStockException(
                $product,
                $needed,
                $needed - $remaining,
                (int) $batches->where(fn (ProductBatch $b) => $b->isExpired())->sum('quantity'),
            );
        }

        return $allocations;
    }

    /**
     * The lot an increase lands in: the undated general bucket, unless the
     * caller supplied a batch number or expiry date, in which case a new dated
     * lot is opened.
     *
     * @param  Collection<int, ProductBatch>  $batches
     */
    private function resolveDepositTarget(Product $product, Collection $batches, array $newBatch): ProductBatch
    {
        $expiryDate = $newBatch['expiry_date'] ?? null;
        $batchNo = $newBatch['batch_no'] ?? null;

        if ($expiryDate === null && $batchNo === null) {
            $general = $batches->first(fn (ProductBatch $batch) => $batch->expiry_date === null);

            if ($general) {
                return $general;
            }
        }

        $created = ProductBatch::create([
            'product_id' => $product->getKey(),
            'batch_no' => $batchNo,
            'expiry_date' => $expiryDate,
            'quantity' => 0,
            'notes' => $newBatch['notes'] ?? null,
        ]);

        $batches->push($created);

        return $created;
    }
}
