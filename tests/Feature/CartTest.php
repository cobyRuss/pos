<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private function cart(): Cart
    {
        return app(Cart::class);
    }

    public function test_it_starts_empty(): void
    {
        $this->assertTrue($this->cart()->isEmpty());
        $this->assertSame(0, $this->cart()->subtotal());
        $this->assertSame(0, $this->cart()->count());
    }

    public function test_adding_a_product_snapshots_its_price_in_cents(): void
    {
        $product = Product::factory()->create(['price' => 4.25, 'stock' => 10]);

        $this->cart()->add($product, 2);

        $line = $this->cart()->get($product->id);
        $this->assertSame(425, $line['unit_price']);
        $this->assertSame(2, $line['quantity']);
        $this->assertSame(850, $this->cart()->subtotal());
        $this->assertSame(2, $this->cart()->count());
    }

    public function test_adding_the_same_product_tops_up_the_quantity(): void
    {
        $product = Product::factory()->create(['price' => 1.00, 'stock' => 10]);

        $this->cart()->add($product, 1);
        $this->cart()->add($product, 2);

        $this->assertSame(3, $this->cart()->quantityOf($product->id));
        $this->assertCount(1, $this->cart()->items());
    }

    public function test_a_repriced_product_keeps_the_quoted_price(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 10]);
        $this->cart()->add($product, 1);

        // An admin changes the price while the basket is open. The sale the
        // cashier already quoted must not move underneath them.
        $product->update(['price' => 99.00]);

        $this->assertSame(1000, $this->cart()->get($product->id)['unit_price']);
        $this->assertSame(1000, $this->cart()->subtotal());
    }

    public function test_the_cart_never_exceeds_available_stock(): void
    {
        $product = Product::factory()->create(['price' => 1.00, 'stock' => 3]);

        $this->cart()->add($product, 10);

        $this->assertSame(3, $this->cart()->quantityOf($product->id));
    }

    public function test_setting_a_quantity_of_zero_removes_the_line(): void
    {
        $product = Product::factory()->create(['price' => 1.00, 'stock' => 10]);
        $this->cart()->add($product, 2);

        $this->cart()->setQuantity($product, 0);

        $this->assertTrue($this->cart()->isEmpty());
    }

    public function test_removing_and_clearing(): void
    {
        $a = Product::factory()->create(['stock' => 5]);
        $b = Product::factory()->create(['stock' => 5]);

        $this->cart()->add($a);
        $this->cart()->add($b);
        $this->cart()->remove($a->id);
        $this->assertFalse($this->cart()->has($a->id));
        $this->assertTrue($this->cart()->has($b->id));

        $this->cart()->clear();
        $this->assertTrue($this->cart()->isEmpty());
    }

    public function test_a_fixed_cart_discount_reduces_the_taxable_amount(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 10]);
        $this->cart()->add($product, 2); // 20.00

        $this->cart()->applyDiscount(Cart::DISCOUNT_FIXED, 5.00);

        $this->assertSame(500, $this->cart()->discountAmount());
        $this->assertSame(1500, $this->cart()->taxableAmount());
        $this->assertSame(150, $this->cart()->taxAmount(10));
        $this->assertSame(1650, $this->cart()->total(10));
    }

    public function test_a_percentage_cart_discount_is_calculated_on_the_subtotal(): void
    {
        $product = Product::factory()->create(['price' => 25.00, 'stock' => 10]);
        $this->cart()->add($product, 2); // 50.00

        $this->cart()->applyDiscount(Cart::DISCOUNT_PERCENT, 10);

        $this->assertSame(500, $this->cart()->discountAmount());
        $this->assertSame(4500, $this->cart()->taxableAmount());
    }

    public function test_a_discount_larger_than_the_cart_is_capped(): void
    {
        $product = Product::factory()->create(['price' => 5.00, 'stock' => 10]);
        $this->cart()->add($product, 1); // 5.00

        $this->cart()->applyDiscount(Cart::DISCOUNT_FIXED, 500.00);

        $this->assertSame(500, $this->cart()->discountAmount());
        $this->assertSame(0, $this->cart()->taxableAmount());
        $this->assertSame(0, $this->cart()->total(10));
    }

    public function test_a_zero_tax_rate_produces_no_tax(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 10]);
        $this->cart()->add($product, 1);

        $this->assertSame(0, $this->cart()->taxAmount(0));
        $this->assertSame(1000, $this->cart()->total(0));
    }

    public function test_a_line_discount_reduces_only_that_line(): void
    {
        $a = Product::factory()->create(['price' => 10.00, 'stock' => 10]);
        $b = Product::factory()->create(['price' => 10.00, 'stock' => 10]);

        $this->cart()->add($a, 1);
        $this->cart()->add($b, 1);
        $this->cart()->discountLine($a->id, Cart::DISCOUNT_FIXED, 3.00);

        $this->assertSame(700, $this->cart()->get($a->id)['line_total']);
        $this->assertSame(1000, $this->cart()->get($b->id)['line_total']);
        $this->assertSame(1700, $this->cart()->subtotal());
    }

    public function test_a_line_discount_cannot_exceed_the_line(): void
    {
        $a = Product::factory()->create(['price' => 2.00, 'stock' => 10]);
        $this->cart()->add($a, 1);
        $this->cart()->discountLine($a->id, Cart::DISCOUNT_FIXED, 99.00);

        $this->assertSame(0, $this->cart()->get($a->id)['line_total']);
        $this->assertSame(0, $this->cart()->subtotal());
    }
}
