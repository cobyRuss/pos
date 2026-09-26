<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cancels a completed sale and puts the stock back on the shelf.
 *
 * Restocking goes through the InventoryService so the reversal is logged like
 * any other movement and an auditor can see the sale and its undo as a pair.
 * The whole thing is one transaction: a cancellation that restored some items
 * but not others would be worse than one that failed outright.
 */
class OrderCancellationService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly LotAllocator $lots,
    ) {}

    /**
     * @throws RuntimeException when the order cannot be cancelled.
     */
    public function cancel(Order $order, User $actor, ?string $reason = null): Order
    {
        if ($order->is_cancelled) {
            throw new RuntimeException("Order [{$order->order_number}] has already been cancelled.");
        }

        if ($order->refunded_total > 0) {
            throw new RuntimeException(
                "Order [{$order->order_number}] has refunds against it. Refund it instead of cancelling."
            );
        }

        return DB::transaction(function () use ($order, $actor, $reason): Order {
            // Locked so two staff members cannot cancel the same order at once.
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->is_cancelled) {
                throw new RuntimeException("Order [{$locked->order_number}] has already been cancelled.");
            }

            foreach ($locked->items()->with(['product', 'allocations.lot'])->get() as $item) {
                // A product deleted since the sale cannot be restocked; the
                // line is still marked refunded-by-cancellation for the record.
                if ($item->product === null) {
                    continue;
                }

                $this->restock($item, $actor, $locked->order_number);
            }

            $locked->forceFill([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'cancelled_by' => $actor->getKey(),
            ])->save();

            return $locked->fresh();
        });
    }

    /**
     * Return a cancelled line to the batches it was sold from.
     *
     * Same rule as refunds: crediting "the current batch" would quietly
     * rewrite expiry dates, so each unit goes back where it came from.
     */
    private function restock(OrderItem $item, User $actor, string $orderNumber): void
    {
        $product = $item->product;

        if ($product === null) {
            return;
        }

        // Ascending, matching the order the units left the batches.
        $allocations = $item->allocations
            ->filter(fn (OrderItemLot $slice) => $slice->lot !== null)
            ->sortBy(fn (OrderItemLot $slice) => $slice->id)
            ->values();

        $remaining = (int) $item->quantity;

        foreach ($allocations as $slice) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (int) $slice->quantity);

            $this->inventory->move(
                product: $product,
                quantityChange: $take,
                type: InventoryService::TYPE_IN,
                user: $actor,
                notes: "Cancellation of {$orderNumber} (batch {$slice->lot->code})",
                referenceType: Order::class,
                referenceId: $item->order_id,
                lot: $slice->lot,
            );

            $remaining -= $take;
        }

        if ($remaining > 0) {
            $recovery = $this->lots->recoveryLot($product);

            $this->inventory->move(
                product: $product,
                quantityChange: $remaining,
                type: InventoryService::TYPE_IN,
                user: $actor,
                notes: "Cancellation of {$orderNumber}; {$remaining} unit(s) could not be traced to a batch and are held in {$recovery->code}.",
                referenceType: Order::class,
                referenceId: $item->order_id,
                lot: $recovery,
            );
        }
    }
}
