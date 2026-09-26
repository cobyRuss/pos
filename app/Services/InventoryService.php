<?php

namespace App\Services;

use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single place where stock levels change.
 *
 * Every mutation writes the matching inventory_logs row inside the same
 * transaction, so a level can never drift away from its audit trail. Callers
 * that need to combine this with other writes (order creation, refunds) should
 * wrap their own DB::transaction() around it and let this nest.
 */
class InventoryService
{
    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public function __construct(private readonly ?LotAllocator $lots = null) {}

    /**
     * Move stock by a signed delta and record the movement.
     *
     * For a product that tracks expiry the delta is applied to a specific
     * batch; the caller passes that batch. products.stock is maintained as a
     * cached total of the batches so the existing screens keep working, but it
     * is never the source of truth for a tracked product.
     *
     * @param  string  $type  One of the TYPE_* constants.
     * @param  User|null  $user  Who performed it, for the audit trail.
     * @param  string|null  $referenceType  Model class the movement belongs to.
     * @param  int|null  $referenceId  Primary key of that model.
     *
     * @throws RuntimeException when the resulting level would go negative.
     */
    public function move(
        Product $product,
        int $quantityChange,
        string $type,
        ?User $user = null,
        ?string $notes = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?ProductLot $lot = null,
    ): InventoryLog {
        if ($quantityChange === 0) {
            throw new RuntimeException('Inventory movement cannot be zero.');
        }

        if (! in_array($type, [self::TYPE_IN, self::TYPE_OUT, self::TYPE_ADJUSTMENT], true)) {
            throw new RuntimeException("Unknown inventory movement type [{$type}].");
        }

        // A tracked product must always name the batch, or the movement log
        // stops reconciling per batch and expiry data quietly rots.
        if ($product->tracks_expiry && $lot === null) {
            throw new RuntimeException(
                "[{$product->name}] tracks expiry, so a stock movement must name the batch it applies to."
            );
        }

        return DB::transaction(function () use ($product, $quantityChange, $type, $user, $notes, $referenceType, $referenceId, $lot) {
            // Serialise concurrent movements on the same product so two
            // terminals selling the last unit cannot both succeed.
            $locked = Product::query()->lockForUpdate()->findOrFail($product->getKey());

            if ($lot !== null) {
                $this->applyToLot($lot, $quantityChange, $locked);
            }

            $newStock = $locked->stock + $quantityChange;

            if ($newStock < 0) {
                throw new RuntimeException(
                    "Insufficient stock for [{$locked->name}]: {$locked->stock} available, ".
                    abs($quantityChange).' requested.'
                );
            }

            $locked->forceFill(['stock' => $newStock])->save();

            return InventoryLog::create([
                'user_id' => $user?->getKey(),
                'product_id' => $locked->getKey(),
                'lot_id' => $lot?->getKey(),
                'quantity_change' => $quantityChange,
                'type' => $type,
                'notes' => $notes,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);
        });
    }

    /**
     * Apply a delta to a batch, keeping the product total in step.
     */
    private function applyToLot(ProductLot $lot, int $quantityChange, Product $product): void
    {
        $locked = ProductLot::query()->lockForUpdate()->findOrFail($lot->getKey());

        $newQuantity = (int) $locked->quantity + $quantityChange;

        if ($newQuantity < 0) {
            throw new RuntimeException(sprintf(
                'Lot [%s] of [%s] holds %d unit(s); cannot remove %d.',
                $locked->code,
                $product->name,
                $locked->quantity,
                abs($quantityChange),
            ));
        }

        $locked->quantity = $newQuantity;
        $locked->save();
    }

    /**
     * Receive stock from a supplier.
     */
    public function restock(Product $product, int $quantity, ?User $user = null, ?string $notes = null): InventoryLog
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Restock quantity must be greater than zero.');
        }

        return $this->move(
            product: $product,
            quantityChange: $quantity,
            type: self::TYPE_IN,
            user: $user,
            notes: $notes,
        );
    }

    /**
     * Remove stock that will not be sold (damage, expiry, loss).
     */
    public function writeOff(Product $product, int $quantity, ?User $user = null, ?string $notes = null): InventoryLog
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Write-off quantity must be greater than zero.');
        }

        return $this->move(
            product: $product,
            quantityChange: -abs($quantity),
            type: self::TYPE_OUT,
            user: $user,
            notes: $notes,
        );
    }

    /**
     * Force the level to an absolute number, recording the difference.
     *
     * This is what a stock count uses: the admin states the truth on the shelf
     * and the log captures how far the system was out.
     *
     * A count that already matches the recorded level is a legitimate no-op
     * and returns null rather than throwing, so a re-submitted form does not
     * look like a failure.
     */
    public function setLevel(Product $product, int $newStock, ?User $user = null, ?string $notes = null): ?InventoryLog
    {
        if ($newStock < 0) {
            throw new RuntimeException('Stock level cannot be negative.');
        }

        return DB::transaction(function () use ($product, $newStock, $user, $notes) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->getKey());

            $difference = $newStock - $locked->stock;

            if ($difference === 0) {
                return null;
            }

            $locked->forceFill(['stock' => $newStock])->save();

            return InventoryLog::create([
                'user_id' => $user?->getKey(),
                'product_id' => $locked->getKey(),
                'quantity_change' => $difference,
                'type' => self::TYPE_ADJUSTMENT,
                'notes' => $notes,
            ]);
        });
    }
}
