<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Events\RefundProcessed;
use App\Exceptions\OrderStateException;
use App\Exceptions\RefundPolicyException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Refunds, for a store where the administrator is never on the till.
 *
 * The assumption is that the people on the counter are trusted but not
 * infallible, and that the owner finds out afterwards from a report. So nothing
 * here tries to stop a determined cashier - a determined cashier can always type
 * a plausible story. What it does instead is make the dishonest path expensive
 * and the honest path frictionless:
 *
 *  - The amount is never accepted from the request. It is derived from the lines
 *    the customer actually bought, tax included, so there is no way to "refund"
 *    a peso amount that was never charged.
 *  - Lines are locked and re-checked inside the transaction, so a receipt cannot
 *    be used twice: the second attempt blocks on the row lock and then fails.
 *  - An idempotency key collapses a retried or double-clicked submit back to the
 *    refund that already exists.
 *  - Every refund is bound to the cashier's own account and timestamped, which is
 *    what the CCTV footage and the daily report get checked against.
 *  - A high-value refund is flagged for review and raises an alert, but is never
 *    held up waiting for a manager who may be asleep.
 */
class RefundService
{
    public const ALERT_CHANNEL = 'telegram';

    /**
     * A deadlock means two transactions grabbed the same rows in opposite order.
     * Lock ordering is now deterministic, so this is belt-and-braces: retrying is
     * always safe because the work is idempotent under the row locks.
     */
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Refund part or all of an order and restock the returned units, atomically.
     *
     * @param  array<int, int>  $quantities  order_item_id => quantity to refund.
     * @param  string|null  $idempotencyKey  Replay guard; a repeated submit returns
     *                                       the original refund instead of paying twice.
     *
     * @throws OrderStateException
     * @throws RefundPolicyException
     */
    public function refund(
        Order $order,
        array $quantities,
        RefundReason $reason,
        ?string $detail,
        string $method,
        ?string $note,
        User $actor,
        ?string $idempotencyKey = null,
    ): Refund {
        // Replay check first, and outside the lock: a double-clicked submit
        // should return instantly rather than queue behind the original.
        //
        // The key is global, not per-order, and that is deliberate. It identifies
        // a *request*; if the same key ever turns up against a different order
        // then the client is confused or someone is probing, and the safe answer
        // to both is to hand back the refund that already exists rather than
        // move money a second time.
        if ($idempotencyKey !== null) {
            $existing = Refund::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                return $existing->load(['items', 'user', 'order']);
            }
        }

        $refund = DB::transaction(function () use ($order, $quantities, $reason, $detail, $method, $note, $actor, $idempotencyKey) {
            $order = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($order->isCancelled()) {
                throw OrderStateException::cannotCancel($order->order_number, 'cancelled');
            }

            // Sorting the keys is not cosmetic. Locking rows in a fixed order
            // means a refund can never deadlock against another refund or a
            // checkout that happens to want the same products.
            $quantities = $this->normaliseQuantities($quantities);

            if ($quantities === []) {
                throw OrderStateException::nothingToRefund($order->order_number);
            }

            /** @var Collection<int, OrderItem> $items */
            $items = $order->items()
                ->whereIn('id', array_keys($quantities))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Any line id that is not on *this* order is refused outright, so a
            // refund cannot be assembled out of two different receipts.
            if ($items->count() !== count($quantities)) {
                throw OrderStateException::nothingToRefund($order->order_number);
            }

            $pricing = $this->priceLines($items, $quantities);

            $this->assertWithinDailyLimit($actor, $pricing['total']);

            $refund = new Refund([
                'refund_number' => Refund::generateRefundNumber(),
                'order_id' => $order->getKey(),
                'user_id' => $actor->getAuthIdentifier(),
                'status' => RefundStatus::Completed,
                'reason_code' => $reason,
                'reason' => $detail,
                'method' => PaymentMethod::from($method),
                'note' => $note,
                'refunded_at' => now(),
                'idempotency_key' => $idempotencyKey,
                'review_required' => $pricing['total'] > Setting::refundReviewThreshold(),
                'tax_amount' => $pricing['tax'],
                // Placeholder: the real total is written once the lines land.
                // Both refund_items.refund_id and the inventory movement's
                // reference need the refund's primary key, so the row has to
                // exist before the lines are written.
                'amount' => 0,
            ]);

            $refund->save();

            foreach ($pricing['lines'] as $line) {
                /** @var OrderItem $item */
                $item = $items->get($line['order_item_id']);
                $quantity = $line['quantity'];

                $refund->items()->create([
                    'order_item_id' => $item->getKey(),
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity' => $quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $line['amount'],
                    'tax_amount' => $line['tax'],
                ]);

                $item->forceFill([
                    'refunded_quantity' => $item->refunded_quantity + $quantity,
                ])->save();

                if ($item->product) {
                    // Back onto the lots the units were sold from, so the expiry
                    // dates they carried are preserved.
                    $this->inventory->returnToLots(
                        $item,
                        $quantity,
                        InventoryMovementType::ReturnRestock,
                        sprintf('Refund %s for order %s', $refund->refund_number, $order->order_number),
                        $refund,
                        $actor,
                    );
                }
            }

            $refund->amount = $pricing['total'];
            $refund->save();

            $order->refunded_amount = round((float) $order->refunded_amount + $pricing['total'], 2);

            $fullyRefunded = $order->items()
                ->whereColumn('refunded_quantity', '>=', 'quantity')
                ->count() === $order->items()->count();

            $order->status = $fullyRefunded
                ? OrderStatus::Refunded
                : OrderStatus::PartiallyRefunded;

            $order->save();

            AuditLogger::record(
                AuditLogger::REFUND_CREATED,
                sprintf(
                    '%s refunded %s against order %s (%s).',
                    $actor->name,
                    Setting::money($refund->amount),
                    $order->order_number,
                    $reason->label(),
                ),
                $refund,
                ['order_status' => $order->status->value],
                [
                    'amount' => (float) $refund->amount,
                    'order_status' => $order->status->value,
                    'reason_code' => $reason->value,
                    'review_required' => (bool) $refund->review_required,
                ],
            );

            return $refund->load(['items', 'user', 'order']);
        }, self::DEADLOCK_ATTEMPTS);

        // Record the alert as outstanding *before* dispatching, so a queue that is
        // not being worked still shows up on the reconciliation report as an
        // undelivered notification instead of vanishing.
        $refund->notificationFor(self::ALERT_CHANNEL);

        // Only after commit, so an alert can never describe a rolled-back refund.
        RefundProcessed::dispatch($refund);

        return $refund;
    }

    /**
     * Cast to positive integers, drop the empty lines, and sort by line id.
     *
     * @param  array<mixed, mixed>  $quantities
     * @return array<int, int>
     */
    private function normaliseQuantities(array $quantities): array
    {
        $clean = [];

        foreach ($quantities as $orderItemId => $quantity) {
            $quantity = (int) $quantity;

            if ($quantity > 0) {
                $clean[(int) $orderItemId] = $quantity;
            }
        }

        ksort($clean);

        return $clean;
    }

    /**
     * Work out what each line costs to refund, tax included.
     *
     * Two rules carry the accuracy:
     *
     *  - A line that is being cleared entirely is paid from its exact remaining
     *    balance rather than the per-unit rate, so repeated rounding can never
     *    leave a peso stranded on the order.
     *  - A partial return uses the tax-inclusive unit rate, so the customer gets
     *    back the same slice of tax they paid.
     *
     * Together these guarantee that clearing every line on an order returns
     * exactly `orders.total`, tax included.
     *
     * @param  Collection<int, OrderItem>  $items
     * @param  array<int, int>  $quantities
     * @return array{total: float, tax: float, lines: list<array{order_item_id: int, quantity: int, amount: float, tax: float}>}
     */
    private function priceLines(Collection $items, array $quantities): array
    {
        $lines = [];
        $total = 0.0;
        $tax = 0.0;

        foreach ($quantities as $orderItemId => $quantity) {
            /** @var OrderItem $item */
            $item = $items->get((int) $orderItemId);
            $quantity = (int) $quantity;

            $available = $item->refundable_quantity;

            if ($quantity > $available) {
                throw OrderStateException::exceedsRefundable($quantity, $available, $item->product_name);
            }

            $refunded = $item->refundItems()->selectRaw('COALESCE(SUM(amount), 0) as amount, COALESCE(SUM(tax_amount), 0) as tax')->first();

            $lineGross = round((float) $item->line_total + (float) $item->tax_amount, 2);
            $sold = max(1, (int) $item->quantity);

            if ($quantity === $available) {
                // Clearing the line. Pay the exact balance outstanding so the
                // order's net total lands on 0.00, not 0.01.
                $amount = round($lineGross - (float) $refunded->amount, 2);
                $lineTax = round((float) $item->tax_amount - (float) $refunded->tax, 2);
            } else {
                $amount = round((float) $item->taxInclusiveUnitTotal() * $quantity, 2);
                $lineTax = round(((float) $item->tax_amount / $sold) * $quantity, 2);
            }

            // Rounding can only ever nudge a few centavos, but a refund must never
            // come out negative or exceed the value of the line.
            $amount = min(max(0.0, $amount), $lineGross);
            $lineTax = min(max(0.0, $lineTax), $amount);

            $lines[] = [
                'order_item_id' => (int) $orderItemId,
                'quantity' => $quantity,
                'amount' => $amount,
                'tax' => $lineTax,
            ];

            $total += $amount;
            $tax += $lineTax;
        }

        return [
            'total' => round($total, 2),
            'tax' => round(min($tax, $total), 2),
            'lines' => $lines,
        ];
    }

    /**
     * Refuse a refund that would take the cashier past the store's daily ceiling.
     *
     * `0` disables the limit. The administrator is exempt: this exists to bound a
     * cashier, not to lock the owner out of their own till.
     *
     * @throws RefundPolicyException
     */
    private function assertWithinDailyLimit(User $actor, float $amount): void
    {
        $limit = Setting::refundDailyLimit();

        if ($limit <= 0 || $actor->isAdmin()) {
            return;
        }

        $used = (float) Refund::query()
            ->where('user_id', $actor->getKey())
            ->whereDate('refunded_at', now()->toDateString())
            ->sum('amount');

        if ($used >= $limit) {
            throw RefundPolicyException::dailyLimitInForce($actor, $used, $limit);
        }

        if ($used + $amount > $limit) {
            throw RefundPolicyException::dailyLimitExceeded($actor, $amount, $limit);
        }
    }
}
