<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

/**
 * Session-backed POS cart.
 *
 * Holds product snapshots so a cart survives product edits between the time it
 * was built and checkout. Stock is re-validated at checkout time.
 */
class CartService
{
    public const SESSION_KEY = 'pos_cart';

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        $cart = Session::get(self::SESSION_KEY, []);

        return is_array($cart) ? $cart + ['items' => [], 'note' => null] : $cart;
    }

    public function put(array $cart): void
    {
        Session::put(self::SESSION_KEY, $cart);
    }

    /**
     * Cart lines, each with the live product attached when it still exists.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function items(): Collection
    {
        $items = $this->raw()['items'] ?? [];

        if ($items === []) {
            return collect();
        }

        $products = Product::with(['category', 'batches'])
            ->whereIn('id', array_keys($items))
            ->get()
            ->keyBy('id');

        return collect($items)
            ->map(function (array $line, int|string $productId) use ($products) {
                $product = $products->get((int) $productId);
                $quantity = (int) $line['quantity'];
                $unitPrice = (float) $line['unit_price'];

                // Expiry is a warning, not a block: the till still shows the item
                // so the cashier can explain it, but stock that has lapsed is
                // never counted towards what can actually be sold.
                $batches = $product?->batches ?? collect();
                $sellable = (int) $batches->filter(fn (ProductBatch $b) => $b->isSellable())->sum('quantity');
                $expired = (int) $batches->filter(fn (ProductBatch $b) => $b->status() === 'expired')->sum('quantity');
                $soonest = $batches
                    ->filter(fn (ProductBatch $b) => $b->hasExpiryDate() && (int) $b->quantity > 0)
                    ->sortBy(fn (ProductBatch $b) => $b->expiry_date->timestamp)
                    ->first();

                return [
                    'product_id' => (int) $productId,
                    'name' => $line['name'],
                    'unit' => $line['unit'] ?? 'pcs',
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'line_total' => round($unitPrice * $quantity, 2),
                    'category' => $product?->category?->name,
                    // Live stock, used to warn the cashier before checkout.
                    'stock' => $product?->stock,
                    'sellable_stock' => $product !== null ? $sellable : null,
                    'expired_stock' => $product !== null ? $expired : null,
                    'expiry_label' => $soonest?->expiryLabel(),
                    // Some of the units on the shelf have lapsed...
                    'is_expired' => $expired > 0,
                    // ...as opposed to all of them, which means it cannot be sold.
                    'is_fully_expired' => $expired > 0 && $sellable === 0,
                    'is_expiring' => $soonest !== null && $soonest->isExpiringSoon(),
                    'is_active' => (bool) ($product?->is_active ?? false),
                    'product_exists' => $product !== null,
                    'stock_ok' => $product !== null && $sellable >= $quantity,
                ];
            })
            ->values();
    }

    public function add(Product $product, int $quantity = 1): void
    {
        $cart = $this->raw();
        $productId = (int) $product->id;
        $quantity = max(1, $quantity);

        $existing = (int) ($cart['items'][$productId]['quantity'] ?? 0);
        $cart['items'][$productId] = [
            'product_id' => $productId,
            'name' => $product->name,
            'unit' => $product->unit,
            'unit_price' => (float) $product->selling_price,
            'quantity' => $existing + $quantity,
        ];

        $this->put($cart);
    }

    public function updateQuantity(int $productId, int $quantity): void
    {
        $cart = $this->raw();

        if (! isset($cart['items'][$productId])) {
            return;
        }

        if ($quantity <= 0) {
            $this->remove($productId);

            return;
        }

        $cart['items'][$productId]['quantity'] = $quantity;
        $this->put($cart);
    }

    public function remove(int $productId): void
    {
        $cart = $this->raw();
        unset($cart['items'][$productId]);
        $this->put($cart);
    }

    public function clear(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    public function setNote(?string $note): void
    {
        $cart = $this->raw();
        $cart['note'] = $note;
        $this->put($cart);
    }

    public function count(): int
    {
        return count($this->raw()['items'] ?? []);
    }

    public function totalQuantity(): int
    {
        return (int) collect($this->raw()['items'] ?? [])->sum('quantity');
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Cart subtotal, tax and grand total.
     *
     * There are no promos: the till totals itself the moment a product is
     * clicked, so the customer simply pays subtotal plus tax.
     *
     * @return array{subtotal: float, tax_rate: float, tax_amount: float, total: float, item_count: int, quantity: int}
     */
    public function totals(): array
    {
        $raw = $this->raw();
        $subtotal = round(
            collect($raw['items'] ?? [])->sum(
                fn (array $line) => round((float) $line['unit_price'] * (int) $line['quantity'], 2)
            ),
            2
        );

        $taxRate = Setting::taxRate();
        $taxAmount = round($subtotal * $taxRate / 100, 2);

        return [
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => round($subtotal + $taxAmount, 2),
            'item_count' => $this->count(),
            'quantity' => $this->totalQuantity(),
        ];
    }
}
