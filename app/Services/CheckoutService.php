<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a cart into a completed order.
 *
 * The whole sale happens inside one transaction. Stock is deducted through the
 * InventoryService, which takes a row lock per product, so two terminals
 * selling the last unit cannot both succeed: the second one is rejected and
 * rolls the entire sale back rather than leaving a half-written order.
 */
class CheckoutService
{
    public function __construct(
        private readonly Cart $cart,
        private readonly InventoryService $inventory,
        private readonly LotAllocator $lots,
    ) {}

    /**
     * @param  array<string, mixed>  $payment
     * @param  bool  $allowExpired  Sell past-date batches. Only ever true for a
     *                              user holding the sell-expired permission, so
     *                              a markdown or a write-off can still be rung
     *                              up by someone authorised to make that call.
     *
     * @throws RuntimeException when the cart cannot be sold as presented.
     */
    public function checkout(User $cashier, array $payment, bool $allowExpired = false): Order
    {
        if ($this->cart->isEmpty()) {
            throw new RuntimeException('The cart is empty.');
        }

        $taxRate = Setting::taxRate();

        // Everything is re-read and re-checked inside the transaction. The
        // cart was assembled against stock levels that may already be stale.
        return DB::transaction(function () use ($cashier, $payment, $taxRate, $allowExpired): Order {
            $lines = $this->cart->lines();
            $subtotal = (int) $lines->sum('line_total');
            $cartDiscount = $this->cart->discountAmount();
            $taxable = max(0, $subtotal - $cartDiscount);
            $taxAmount = $taxRate > 0 ? Money::percentOf($taxable, $taxRate) : 0;
            $total = $taxable + $taxAmount;

            $amountPaid = Money::toCents($payment['amount_paid'] ?? 0);
            $method = $payment['payment_method'];

            if ($method === 'cash' && $amountPaid < $total) {
                throw new RuntimeException(
                    'Cash received is less than the total due. Short by '.Money::format($total - $amountPaid).'.',
                );
            }

            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $cashier->getKey(),
                'subtotal' => Money::fromCents($subtotal),
                'discount_type' => $this->cart->discount()['type'],
                'discount_value' => $this->cart->discount()['value'],
                'discount_amount' => Money::fromCents($cartDiscount),
                'tax_rate' => $taxRate,
                'tax_amount' => Money::fromCents($taxAmount),
                'total' => Money::fromCents($total),
                'payment_method' => $method,
                'reference_no' => $payment['reference_no'] ?? null,
                'amount_paid' => Money::fromCents($amountPaid),
                'change_amount' => Money::fromCents(max(0, $amountPaid - $total)),
                'walkin_customer_name' => $payment['walkin_customer_name'] ?? null,
                'notes' => $payment['notes'] ?? null,
                'status' => Order::STATUS_COMPLETED,
            ]);

            foreach ($lines as $line) {
                /** @var array<string, mixed> $line */
                $product = Product::query()->findOrFail($line['product_id']);

                if (! $product->is_active) {
                    throw new RuntimeException("[{$product->name}] is no longer available for sale.");
                }

                // FEFO: decide the batches before writing the line, so an
                // impossible allocation leaves no order behind. Empty for a
                // product that does not track expiry.
                $allocation = $this->lots->allocate($product, (int) $line['quantity'], $allowExpired);

                $orderItem = $order->items()->create([
                    'product_id' => $product->getKey(),
                    'product_name' => $line['name'],
                    'sku' => $line['sku'],
                    'unit_price' => Money::fromCents($line['unit_price']),
                    // A tracked product's cost comes from the batch actually
                    // sold, not the product default, so margin is real.
                    'unit_cost' => $this->lineCost($product, $line, $allocation),
                    'quantity' => $line['quantity'],
                    'discount_amount' => Money::fromCents($line['discount_amount']),
                    'line_total' => Money::fromCents($line['line_total']),
                ]);

                foreach ($allocation as $slice) {
                    /** @var ProductLot $lot */
                    $lot = $slice['lot'];

                    $orderItem->allocations()->create([
                        'lot_id' => $lot->getKey(),
                        'quantity' => $slice['quantity'],
                    ]);
                }

                // One movement per batch actually drawn from. Recording the
                // whole line against the first batch would fail the moment a
                // sale spans two, because that batch does not hold the rest.
                $slices = $allocation === []
                    ? [['lot' => null, 'quantity' => (int) $line['quantity']]]
                    : $allocation;

                foreach ($slices as $slice) {
                    /** @var ProductLot|null $lot */
                    $lot = $slice['lot'];

                    $this->inventory->move(
                        product: $product,
                        quantityChange: -$slice['quantity'],
                        type: InventoryService::TYPE_OUT,
                        user: $cashier,
                        notes: $lot === null
                            ? "Sale {$order->order_number}"
                            : "Sale {$order->order_number} (batch {$lot->code})",
                        referenceType: Order::class,
                        referenceId: $order->getKey(),
                        lot: $lot,
                    );
                }
            }

            $this->cart->clear();

            return $order->load('items');
        });
    }

    /**
     * The cost to snapshot onto the order line.
     *
     * A batch carries the price that batch was bought at, so it wins over the
     * product default. The cart's snapshot is the fallback for products that
     * do not track expiry.
     *
     * @param  array<string, mixed>  $line
     * @param  array<int, array{lot: ProductLot, quantity: int}>  $allocation
     */
    private function lineCost(Product $product, array $line, array $allocation): ?string
    {
        $quantity = (int) $line['quantity'];

        // Weighted across batches, so a line spanning two deliveries at
        // different costs records a blended cost rather than the first one.
        $fromLots = 0;
        $seen = 0;

        foreach ($allocation as $slice) {
            $lotCost = $slice['lot']->cost === null ? null : Money::toCents($slice['lot']->cost);

            if ($lotCost !== null) {
                $fromLots += $lotCost * $slice['quantity'];
                $seen += $slice['quantity'];
            }
        }

        if ($seen > 0) {
            $blended = (int) round($fromLots / $seen);

            return Money::fromCents($blended);
        }

        if ($product->cost !== null) {
            return Money::fromCents(Money::toCents($product->cost));
        }

        return $line['unit_cost'] === null ? null : Money::fromCents($line['unit_cost']);
    }

    /**
     * A short, human-quotable order number that is unique under the index.
     *
     * The random suffix is not relied on for uniqueness; collisions are retried
     * so a clash costs a loop rather than a duplicate sale number.
     */
    private function generateOrderNumber(): string
    {
        $attempts = 0;

        while ($attempts < 5) {
            $number = 'POS-'.now()->format('ymd').'-'.Str::upper(Str::random(4));
            $attempts++;

            if (! Order::where('order_number', $number)->exists()) {
                return $number;
            }
        }

        throw new RuntimeException('Could not allocate a unique order number. Please retry the sale.');
    }
}
