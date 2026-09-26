<?php

namespace App\Services;

use App\Models\Product;
use App\Support\Money;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

/**
 * The in-progress sale.
 *
 * The cart lives in the session rather than in the browser, so a customer
 * cannot edit a price, and a refresh mid-till does not lose the sale. Prices
 * and names are snapshotted when an item is added: if an admin re-prices a
 * product while it sits in someone's cart, the sale the cashier already quoted
 * must not silently change underneath them.
 *
 * All money is held as integer cents; see App\Support\Money.
 */
class Cart
{
    public const DISCOUNT_NONE = 'none';

    public const DISCOUNT_FIXED = 'fixed';

    public const DISCOUNT_PERCENT = 'percent';

    /**
     * Cart lines and the cart discount are held under sibling keys. They must
     * not be nested: the session store treats "." as a path separator, so
     * "pos.cart.discount" would be written *inside* the cart.
     */
    public const SESSION_KEY = 'pos.cart';

    public const DISCOUNT_KEY = 'pos_cart_discount';

    public function __construct(private readonly Session $session) {}

    /**
     * Raw cart lines, keyed by product id.
     *
     * This is the stored shape. Callers should prefer lines(), which resolves
     * the derived money figures onto each line.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        $items = $this->session->get(self::SESSION_KEY, []);

        // Stored as an object so integer types survive the session round trip;
        // callers always want a plain array.
        return (array) $items;
    }

    public function isEmpty(): bool
    {
        return $this->items() === [];
    }

    public function count(): int
    {
        return array_sum(array_column($this->items(), 'quantity'));
    }

    public function has(int $productId): bool
    {
        return array_key_exists($productId, $this->items());
    }

    /**
     * A single line with its derived figures resolved, in the same shape as
     * lines(). Returns null when the product is not in the cart.
     *
     * @return array<string, mixed>|null
     */
    public function get(int $productId): ?array
    {
        return $this->lines()->get($productId);
    }

    public function quantityOf(int $productId): int
    {
        return (int) ($this->items()[$productId]['quantity'] ?? 0);
    }

    /**
     * Add a product, or top up the quantity if it is already in the cart.
     *
     * The caller is expected to have checked availability; the ceiling is
     * re-applied here so the cart can never hold more than the shelf has.
     */
    public function add(Product $product, int $quantity = 1): void
    {
        $existing = $this->quantityOf($product->getKey());
        $requested = $existing + max(1, $quantity);

        $this->put($product, min($requested, max(0, (int) $product->stock)));
    }

    /**
     * Replace a line's quantity. A quantity of zero removes the line.
     */
    public function setQuantity(Product $product, int $quantity): void
    {
        if ($quantity <= 0) {
            $this->remove($product->getKey());

            return;
        }

        $this->put($product, min($quantity, max(0, (int) $product->stock)));
    }

    public function remove(int $productId): void
    {
        $items = $this->items();
        unset($items[$productId]);
        $this->write($items);
    }

    public function clear(): void
    {
        $this->session->forget([
            self::SESSION_KEY,
            self::DISCOUNT_KEY,
        ]);
    }

    /**
     * Apply a discount to a single line.
     */
    public function discountLine(int $productId, string $type, float $value): void
    {
        $items = $this->items();

        if (! isset($items[$productId])) {
            return;
        }

        $items[$productId]['discount_type'] = $type;
        $items[$productId]['discount_value'] = $value;

        $this->write($items);
    }

    public function clearLineDiscount(int $productId): void
    {
        $this->discountLine($productId, self::DISCOUNT_NONE, 0);
    }

    /**
     * The discount applied to the whole cart.
     *
     * @return array{type: string, value: float}
     */
    public function discount(): array
    {
        return $this->session->get(self::DISCOUNT_KEY, [
            'type' => self::DISCOUNT_NONE,
            'value' => 0,
        ]);
    }

    public function applyDiscount(string $type, float $value): void
    {
        $this->session->put(self::DISCOUNT_KEY, [
            'type' => $type,
            'value' => $value,
        ]);
    }

    public function clearDiscount(): void
    {
        $this->session->forget(self::DISCOUNT_KEY);
    }

    /**
     * Sum of the line totals after each line's own discount.
     */
    public function subtotal(): int
    {
        return (int) $this->lines()->sum(fn (array $line): int => $line['line_total']);
    }

    /**
     * The whole-cart discount, capped at the subtotal.
     */
    public function discountAmount(): int
    {
        $discount = $this->discount();

        return match ($discount['type']) {
            self::DISCOUNT_FIXED => Money::cap(Money::toCents($discount['value']), $this->subtotal()),
            self::DISCOUNT_PERCENT => Money::cap(
                Money::percentOf($this->subtotal(), (float) $discount['value']),
                $this->subtotal(),
            ),
            default => 0,
        };
    }

    /**
     * What the tax is calculated on: the subtotal less the cart discount.
     */
    public function taxableAmount(): int
    {
        return max(0, $this->subtotal() - $this->discountAmount());
    }

    public function taxAmount(float $taxRate): int
    {
        return $taxRate > 0 ? Money::percentOf($this->taxableAmount(), $taxRate) : 0;
    }

    public function total(float $taxRate = 0.0): int
    {
        return $this->taxableAmount() + $this->taxAmount($taxRate);
    }

    /**
     * Cart lines with every derived figure resolved, ready for display.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lines(): Collection
    {
        return collect($this->items())->map(function (array $line): array {
            $gross = $line['unit_price'] * $line['quantity'];

            $discount = match ($line['discount_type']) {
                self::DISCOUNT_FIXED => Money::cap(Money::toCents($line['discount_value']), $gross),
                self::DISCOUNT_PERCENT => Money::cap(
                    Money::percentOf($gross, (float) $line['discount_value']),
                    $gross,
                ),
                default => 0,
            };

            return [
                ...$line,
                'gross' => $gross,
                'discount_amount' => $discount,
                'line_total' => $gross - $discount,
            ];
        });
    }

    /**
     * Write a line, keeping the price and name frozen at the current values.
     */
    private function put(Product $product, int $quantity): void
    {
        $items = $this->items();
        $productId = (int) $product->getKey();
        $existing = $items[$productId] ?? null;

        $items[$productId] = [
            'product_id' => $productId,
            'name' => $existing['name'] ?? $product->name,
            'sku' => $existing['sku'] ?? $product->sku,
            'unit_price' => $existing['unit_price'] ?? Money::toCents($product->price),
            'unit_cost' => $existing['unit_cost'] ?? ($product->cost === null ? null : Money::toCents($product->cost)),
            'quantity' => $quantity,
            'discount_type' => $existing['discount_type'] ?? self::DISCOUNT_NONE,
            'discount_value' => $existing['discount_value'] ?? 0,
        ];

        $this->write($items);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function write(array $items): void
    {
        // Stored as an object so the cart survives json_encode in the session
        // driver and keeps integer types on the round trip.
        $this->session->put(self::SESSION_KEY, (object) $items);
    }
}
