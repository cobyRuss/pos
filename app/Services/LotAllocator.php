<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductLot;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Decides which batch a sale draws from.
 *
 * First Expired, First Out: the batch closest to its expiry date is sold
 * first, so short-dated stock does not get buried under a newer delivery. A
 * batch with no recorded expiry is treated as the longest-lived and sold last,
 * because "unknown" must never be read as "most urgent".
 *
 * Rows are locked while the allocation is made, so two terminals cannot both
 * claim the same last unit of the same batch.
 */
class LotAllocator
{
    /**
     * Code of the holding batch for returns whose original batch is gone.
     */
    public const RECOVERY_CODE = 'UNASSIGNED';

    /**
     * Choose the batches to satisfy a sale of $quantity units.
     *
     * @return array<int, array{lot: ProductLot, quantity: int}>
     *
     * @throws RuntimeException when there is not enough sellable stock.
     */
    public function allocate(Product $product, int $quantity, bool $allowExpired = false): array
    {
        if ($quantity < 1) {
            return [];
        }

        // A product that does not track expiry has nothing to allocate: its
        // single stock count is the whole story.
        if (! $product->tracks_expiry) {
            $this->guardSufficientStock($product, $quantity, $product->stock, null);

            return [];
        }

        $lots = $this->sellableLots($product, $allowExpired);

        $available = (int) $lots->sum('quantity');

        $this->guardSufficientStock($product, $quantity, $available, $allowExpired ? null : 'expired');

        $allocation = [];
        $remaining = $quantity;

        // fefo() already orders by expiry, with undated batches last.
        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (int) $lot->quantity);

            if ($take > 0) {
                $allocation[] = ['lot' => $lot, 'quantity' => $take];
                $remaining -= $take;
            }
        }

        return $allocation;
    }

    /**
     * The batches that may be sold, locked for update and in FEFO order.
     *
     * @return Collection<int, ProductLot>
     */
    public function sellableLots(Product $product, bool $allowExpired = false, bool $lock = true): Collection
    {
        $query = $product->lots()
            ->inStock()
            ->fefo();

        if (! $allowExpired) {
            $query->where(function ($q): void {
                $q->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', today()->toDateString());
            });
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * The earliest expiry among sellable batches, for the till to display.
     */
    public function nextExpiry(Product $product, bool $allowExpired = false): ?string
    {
        $lot = $this->sellableLots($product, $allowExpired, lock: false)->first();

        return $lot?->expires_at?->toDateString();
    }

    /**
     * Ensure a batch exists with the given metadata, without touching stock.
     *
     * The quantity is deliberately NOT applied here. Stock is only ever
     * changed through InventoryService::move, which also writes the movement
     * log and keeps the cached product total in step. Creating the batch and
     * then moving stock into it keeps a single path for every quantity change.
     */
    public function batchFor(
        Product $product,
        string $code,
        ?string $expiresAt = null,
        ?float $cost = null,
        ?string $notes = null,
    ): ProductLot {
        $lot = $product->lots()->lockForUpdate()->firstWhere('code', $code);

        if ($lot === null) {
            return $product->lots()->create([
                'code' => $code,
                'expires_at' => $expiresAt,
                'quantity' => 0,
                'cost' => $cost,
                'received_at' => today()->toDateString(),
                'notes' => $notes,
            ]);
        }

        $changed = false;

        // A later delivery may correct or extend a date, or restate the cost.
        if ($expiresAt !== null && $lot->expires_at !== $expiresAt) {
            $lot->expires_at = $expiresAt;
            $changed = true;
        }

        if ($cost !== null && (float) $lot->cost !== $cost) {
            $lot->cost = $cost;
            $changed = true;
        }

        if ($lot->received_at === null) {
            $lot->received_at = today()->toDateString();
            $changed = true;
        }

        if ($changed) {
            $lot->save();
        }

        return $lot;
    }

    /**
     * Receive a delivery: ensure the batch exists, then move stock into it.
     *
     * The quantity change goes through InventoryService so the movement is
     * logged and the cached product total stays in step — applying it here as
     * well would double-count.
     */
    public function receive(
        Product $product,
        string $code,
        int $quantity,
        ?string $expiresAt = null,
        ?float $cost = null,
        ?string $notes = null,
        ?User $user = null,
    ): ProductLot {
        if ($quantity < 1) {
            throw new RuntimeException('A batch must receive at least one unit.');
        }

        $lot = $this->batchFor($product, $code, $expiresAt, $cost, $notes);

        app(InventoryService::class)->move(
            product: $product,
            quantityChange: $quantity,
            type: InventoryService::TYPE_IN,
            user: $user,
            notes: "Received into batch {$lot->code}",
            lot: $lot,
        );

        return $lot->fresh();
    }

    /**
     * Reduce a batch, refusing to take more than it holds.
     */
    public function release(ProductLot $lot, int $quantity): ProductLot
    {
        $locked = ProductLot::query()->lockForUpdate()->findOrFail($lot->getKey());

        if ($quantity < 0) {
            throw new RuntimeException("Lot [{$locked->code}] cannot be increased through a sale.");
        }

        if ($quantity > (int) $locked->quantity) {
            throw new RuntimeException(sprintf(
                'Lot [%s] holds %d unit(s); cannot release %d.',
                $locked->code,
                $locked->quantity,
                $quantity,
            ));
        }

        $locked->quantity = (int) $locked->quantity - $quantity;
        $locked->save();

        return $locked;
    }

    /**
     * Put units back into the batch they came from.
     */
    public function returnTo(ProductLot $lot, int $quantity): ProductLot
    {
        $locked = ProductLot::query()->lockForUpdate()->findOrFail($lot->getKey());

        $locked->quantity = (int) $locked->quantity + $quantity;
        $locked->save();

        return $locked;
    }

    /**
     * The batch that receives units whose original batch no longer exists.
     *
     * A batch can be deleted after a sale, and a return still has to go
     * somewhere: dropping the units would quietly lose stock, and crediting the
     * product total would break the rule that a tracked product's every
     * movement names a batch. A single undated holding batch keeps both
     * promises and is visible to an administrator as something to reconcile.
     */
    public function recoveryLot(Product $product): ProductLot
    {
        $existing = $product->lots()->where('code', self::RECOVERY_CODE)->first();

        if ($existing !== null) {
            return $existing;
        }

        return $product->lots()->create([
            'code' => self::RECOVERY_CODE,
            'expires_at' => null,
            'quantity' => 0,
            'cost' => $product->cost,
            'received_at' => today()->toDateString(),
            'notes' => 'Holds units whose original batch was deleted. Reconcile and re-file these.',
        ]);
    }

    /**
     * Refuse an over-draw with a message a cashier can act on.
     */
    private function guardSufficientStock(Product $product, int $quantity, int $available, ?string $cause): void
    {
        if ($available >= $quantity) {
            return;
        }

        $message = match ($cause) {
            'expired' => sprintf(
                'Only %d sellable unit(s) of [%s] left; the rest has expired.',
                $available,
                $product->name,
            ),
            default => sprintf(
                'Only %d of [%s] in stock, %d requested.',
                $available,
                $product->name,
                $quantity,
            ),
        };

        throw new RuntimeException($message);
    }
}
