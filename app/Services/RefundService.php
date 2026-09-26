<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemLot;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Returns and refunds.
 *
 * A refund is money leaving the till and goods coming back, so every rule here
 * exists to stop the shop being drained twice over:
 *
 *  - A line can only be returned up to what is still returnable, counted as
 *    what was sold minus everything already refunded on it. The check happens
 *    against a locked read, so two tills refunding the same units at the same
 *    moment cannot both succeed.
 *  - A cancelled order is never refundable; the sale was already undone and
 *    the stock already returned.
 *  - Refunds are capped at the order total, so a partially returned order can
 *    never drive refunded_total past what the customer actually paid.
 *  - The order's tax and cart discount are shared across the lines in
 *    proportion to each line's share of the subtotal, so returning every unit
 *    refunds exactly what was charged and the order can genuinely reach
 *    "fully refunded". Crediting list price instead would strand the tax.
 *  - Only restockable goods re-enter sellable stock. Damaged returns are
 *    recorded as a write-off on the refund line and deliberately do not touch
 *    the product, because a scrapped unit is not inventory.
 */
class RefundService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly LotAllocator $lots,
    ) {}

    /**
     * Process a return against an order.
     *
     * @param  array<int, array{order_item_id: int, quantity: int, condition: string}>  $lines
     */
    public function refund(
        Order $order,
        User $actor,
        array $lines,
        string $method,
        ?string $reason = null,
        ?string $referenceNo = null,
    ): Refund {
        if ($order->is_cancelled) {
            throw new RuntimeException(
                "Order [{$order->order_number}] was cancelled, so there is nothing to refund."
            );
        }

        $lines = array_values(array_filter(
            $lines,
            fn (array $line) => (int) ($line['quantity'] ?? 0) > 0,
        ));

        if ($lines === []) {
            throw new RuntimeException('Select at least one item to return.');
        }

        $invalid = array_filter($lines, fn (array $line) => ! in_array(
            $line['condition'] ?? null,
            [RefundItem::CONDITION_RESTOCKABLE, RefundItem::CONDITION_DAMAGED],
            true,
        ));

        if ($invalid !== []) {
            throw new RuntimeException('Every returned item needs a condition.');
        }

        if ($method === Refund::METHOD_DIGITAL && blank($referenceNo)) {
            throw new RuntimeException('Enter the e-wallet or terminal reference for a digital refund.');
        }

        return DB::transaction(function () use ($order, $actor, $lines, $method, $reason, $referenceNo): Refund {
            // The order row is locked for the whole transaction so two refunds
            // cannot interleave and each believe it was acting on the
            // pre-refund balance.
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            // Scoped to this order as well as checked for existence: a line id
            // from another sale must not be refundable through this service.
            $items = OrderItem::query()
                ->where('order_id', $locked->getKey())
                ->whereIn('id', array_column($lines, 'order_item_id'))
                ->lockForUpdate()
                ->with('allocations.lot')
                ->get()
                ->keyBy('id');

            if ($items->count() !== count($lines)) {
                throw new RuntimeException('One of the selected items is not part of this order.');
            }

            $refund = $locked->refunds()->create([
                'user_id' => $actor->getKey(),
                'total_amount' => 0,
                'method' => $method,
                'reference_no' => $referenceNo,
                'reason' => $reason,
            ]);

            $total = 0;

            // The order's tax and cart discount are held on the order, not on
            // its lines, so they are shared across the lines in proportion to
            // each line's share of the subtotal. Without this a fully returned
            // order would only ever give back the line totals, stranding the
            // tax the customer actually paid and leaving the order permanently
            // short of "fully refunded".
            $orderSubtotal = Money::toCents($locked->subtotal);
            $orderLevy = Money::toCents($locked->tax_amount) + Money::toCents($locked->discount_amount);

            foreach ($lines as $line) {
                /** @var OrderItem $item */
                $item = $items->get((int) $line['order_item_id']);

                $quantity = (int) $line['quantity'];
                $returnable = $item->returnable_quantity;
                $soldQuantity = max(1, (int) $item->quantity);

                if ($quantity > $returnable) {
                    throw new RuntimeException(sprintf(
                        'Only %d of [%s] can still be returned (already refunded: %d).',
                        $returnable,
                        $item->product_name,
                        $item->quantity - $returnable,
                    ));
                }

                $lineCents = Money::toCents($item->line_total);

                // Credit what the customer actually paid per unit: the line
                // total already has the line discount applied, and a
                // proportional slice of the order-level levy is added back.
                $amount = (int) round($lineCents * $quantity / $soldQuantity);

                if ($orderSubtotal > 0 && $orderLevy > 0) {
                    $lineLevy = (int) round($orderLevy * $lineCents / $orderSubtotal);
                    $amount += (int) round($lineLevy * $quantity / $soldQuantity);
                }

                $condition = $line['condition'];

                $refundItem = $refund->items()->create([
                    'order_item_id' => $item->getKey(),
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity' => $quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => Money::fromCents($amount),
                    'condition' => $condition,
                    'restocked' => $condition === RefundItem::CONDITION_RESTOCKABLE,
                ]);

                $total += $amount;

                // Damaged goods are scrapped: recorded on the refund line, but
                // never returned to sellable stock.
                if ($refundItem->restocked) {
                    $this->restock($item, $quantity, $actor, $locked->order_number, $refund);
                }
            }

            $orderTotal = Money::toCents($locked->total);
            $alreadyRefunded = Money::toCents($locked->refunded_total);

            // Never hand back more than the customer paid, even if the lines
            // add up to more than the order was ever worth.
            $total = min($total, max(0, $orderTotal - $alreadyRefunded));

            $refund->forceFill(['total_amount' => Money::fromCents($total)])->save();

            $locked->forceFill([
                'refunded_total' => Money::fromCents($alreadyRefunded + $total),
            ])->save();

            return $refund->load('items');
        });
    }

    /**
     * Put returned units back where they came from.
     *
     * The units are credited to the batches recorded on the original order
     * line, newest-consumed-first within the allocation. Returning them to
     * "whatever batch is current" would be a quiet corruption: milk that
     * expires in three days would be folded into a delivery that expires in
     * three months and the shop would keep selling it long past its date.
     *
     * A batch deleted since the sale cannot receive the units. Rather than
     * dropping them, they go to a holding batch so the stock total stays
     * correct and an administrator can see them to reconcile.
     */
    private function restock(OrderItem $item, int $quantity, User $actor, string $orderNumber, Refund $refund): void
    {
        $product = $item->product;

        if ($product === null) {
            // The product itself is gone; there is nothing to put stock back on.
            return;
        }

        // Walk the allocations in the order the units originally left the
        // batches (ascending), so a returned unit goes back to the batch it
        // most plausibly came from. This is FIFO within a line, not FEFO: the
        // customer is handing back an undifferentiated unit, and the earliest
        // batch they drew from is the safest home for it.
        $allocations = $item->allocations
            ->filter(fn (OrderItemLot $slice) => $slice->lot !== null)
            ->sortBy(fn (OrderItemLot $slice) => $slice->id)
            ->values();

        $remaining = $quantity;

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
                notes: "Return against {$orderNumber} (batch {$slice->lot->code})",
                referenceType: Refund::class,
                referenceId: $refund->getKey(),
                lot: $slice->lot,
            );

            $remaining -= $take;
        }

        if ($remaining > 0) {
            // The units are real and the customer has them, so they must be
            // booked somewhere. A tracked product cannot take a batch-less
            // movement, so they go into a holding batch an administrator can
            // see and reconcile rather than vanishing.
            $recovery = $this->lots->recoveryLot($product);

            $this->inventory->move(
                product: $product,
                quantityChange: $remaining,
                type: InventoryService::TYPE_IN,
                user: $actor,
                notes: "Return against {$orderNumber}; {$remaining} unit(s) could not be traced to a batch and are held in {$recovery->code}.",
                referenceType: Refund::class,
                referenceId: $refund->getKey(),
                lot: $recovery,
            );
        }
    }

    /**
     * Refund every remaining unit on an order in one go.
     */
    public function refundAll(Order $order, User $actor, string $method, ?string $reason = null, ?string $referenceNo = null): Refund
    {
        $lines = $order->items()
            ->get()
            ->map(fn (OrderItem $item): array => [
                'order_item_id' => $item->getKey(),
                'quantity' => $item->returnable_quantity,
                'condition' => RefundItem::CONDITION_RESTOCKABLE,
            ])
            ->reject(fn (array $line) => $line['quantity'] < 1)
            ->all();

        return $this->refund($order, $actor, $lines, $method, $reason, $referenceNo);
    }
}
