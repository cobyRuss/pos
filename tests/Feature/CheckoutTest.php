<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create();
        $this->staff->assignRole(Role::Staff);
    }

    private function cart(): Cart
    {
        return app(Cart::class);
    }

    private function fillCart(Product $product, int $quantity = 1): void
    {
        $this->cart()->add($product, $quantity);
    }

    public function test_a_cash_sale_creates_an_order_and_reduces_stock(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 10]);
        $this->fillCart($product, 2);

        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), [
                'payment_method' => 'cash',
                'amount_paid' => 25.00,
            ])
            ->assertRedirect();

        $order = Order::with('items')->sole();

        $this->assertSame('cash', $order->payment_method);
        $this->assertSame('20.00', $order->subtotal);
        $this->assertSame('20.00', $order->total);
        $this->assertSame('25.00', $order->amount_paid);
        $this->assertSame('5.00', $order->change_amount);
        $this->assertSame($this->staff->id, $order->user_id);
        $this->assertSame(Order::STATUS_COMPLETED, $order->status);

        $this->assertCount(1, $order->items);
        $line = $order->items->first();
        $this->assertSame($product->id, $line->product_id);
        $this->assertSame(2, $line->quantity);
        $this->assertSame('10.00', $line->unit_price);
        $this->assertSame('20.00', $line->line_total);

        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_a_sale_writes_an_inventory_movement_referencing_the_order(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 5]);
        $this->fillCart($product, 3);

        $this->actingAs($this->staff)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'amount_paid' => 100,
        ]);

        $order = Order::sole();
        $log = InventoryLog::where('product_id', $product->id)->sole();

        $this->assertSame(-3, $log->quantity_change);
        $this->assertSame('out', $log->type);
        $this->assertSame(Order::class, $log->reference_type);
        $this->assertSame($order->id, $log->reference_id);
        $this->assertSame($this->staff->id, $log->user_id);
    }

    public function test_the_cart_is_emptied_after_a_successful_sale(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 5]);
        $this->fillCart($product, 1);

        $this->actingAs($this->staff)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'amount_paid' => 100,
        ]);

        $this->assertTrue($this->cart()->isEmpty());
    }

    public function test_exact_change_is_recorded_as_zero(): void
    {
        $product = Product::factory()->create(['price' => 7.50, 'stock' => 5]);
        $this->fillCart($product, 1);

        $this->actingAs($this->staff)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'amount_paid' => 7.50,
        ]);

        $this->assertSame('0.00', Order::sole()->change_amount);
    }

    public function test_a_card_payment_must_match_the_total_exactly(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 5]);
        $this->fillCart($product, 1);

        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), [
                'payment_method' => 'card',
                'amount_paid' => 5.00,
            ])
            ->assertSessionHasErrors('amount_paid');

        $this->assertSame(0, Order::count());
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_a_card_payment_is_accepted_at_the_exact_total(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 5]);
        $this->fillCart($product, 1);

        $this->actingAs($this->staff)->post(route('pos.checkout'), [
            'payment_method' => 'card',
            'amount_paid' => 10.00,
        ]);

        $order = Order::sole();
        $this->assertSame('card', $order->payment_method);
        $this->assertSame('0.00', $order->change_amount);
    }

    public function test_a_digital_payment_requires_a_reference_number(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 5]);
        $this->fillCart($product, 1);

        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), [
                'payment_method' => 'digital',
                'amount_paid' => 10.00,
            ])
            ->assertSessionHasErrors('reference_no');

        $this->assertSame(0, Order::count());
    }

    public function test_a_digital_payment_stores_its_reference(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 5]);
        $this->fillCart($product, 1);

        $this->actingAs($this->staff)->post(route('pos.checkout'), [
            'payment_method' => 'digital',
            'amount_paid' => 10.00,
            'reference_no' => 'EWT-88213',
        ]);

        $this->assertSame('EWT-88213', Order::sole()->reference_no);
    }

    public function test_insufficient_cash_is_rejected_and_nothing_is_written(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 5]);
        $this->fillCart($product, 2);

        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), [
                'payment_method' => 'cash',
                'amount_paid' => 5.00,
            ])
            ->assertSessionHasErrors('amount_paid');

        $this->assertSame(0, Order::count());
        $this->assertSame(5, $product->fresh()->stock);
        // The basket survives a failed sale so the cashier can retry.
        $this->assertFalse($this->cart()->isEmpty());
    }

    public function test_stock_sold_by_another_terminal_fails_the_whole_sale(): void
    {
        $first = Product::factory()->create(['price' => 10.00, 'stock' => 5]);
        $second = Product::factory()->create(['price' => 10.00, 'stock' => 5]);

        $this->fillCart($first, 1);
        $this->fillCart($second, 1);

        // Another register sells the second product out from under this cart.
        $second->update(['stock' => 0]);

        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), [
                'payment_method' => 'cash',
                'amount_paid' => 100,
            ]);

        // The order must not exist, and the first product must not have been
        // decremented: the transaction rolls the whole basket back.
        $this->assertSame(0, Order::count());
        $this->assertSame(5, $first->fresh()->stock);
        $this->assertSame(0, InventoryLog::count());
    }

    public function test_a_product_archived_mid_sale_fails_the_checkout(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 5]);
        $this->fillCart($product, 1);

        $product->update(['is_active' => false]);

        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), [
                'payment_method' => 'cash',
                'amount_paid' => 100,
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Order::count());
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_tax_is_applied_from_settings(): void
    {
        Setting::put('tax_rate', '10');

        $product = Product::factory()->create(['price' => 20.00, 'stock' => 5]);
        $this->fillCart($product, 1);

        $this->actingAs($this->staff)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'amount_paid' => 100,
        ]);

        $order = Order::sole();
        $this->assertSame('20.00', $order->subtotal);
        $this->assertSame('2.00', $order->tax_amount);
        $this->assertSame('22.00', $order->total);
    }

    public function test_a_cart_discount_is_applied_before_tax(): void
    {
        Setting::put('tax_rate', '10');

        $product = Product::factory()->create(['price' => 20.00, 'stock' => 5]);
        $this->fillCart($product, 1);
        $this->cart()->applyDiscount(Cart::DISCOUNT_FIXED, 5.00);

        $this->actingAs($this->staff)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'amount_paid' => 100,
        ]);

        $order = Order::sole();
        $this->assertSame('20.00', $order->subtotal);
        $this->assertSame('fixed', $order->discount_type);
        $this->assertSame('5.00', $order->discount_amount);
        // 15.00 taxable, 10% tax => 16.50
        $this->assertSame('1.50', $order->tax_amount);
        $this->assertSame('16.50', $order->total);
    }

    public function test_the_unit_cost_is_snapshotted_onto_the_order_line(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'cost' => 6.00, 'stock' => 5]);
        $this->fillCart($product, 2);

        $this->actingAs($this->staff)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'amount_paid' => 100,
        ]);

        $line = Order::sole()->items->first();
        $this->assertSame('6.00', $line->unit_cost);

        // A later cost change must not rewrite the margin of a past sale.
        $product->update(['cost' => 9.99]);
        $this->assertSame('6.00', $line->fresh()->unit_cost);
    }

    public function test_the_sale_records_the_walkin_customer_name(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 5]);
        $this->fillCart($product, 1);

        $this->actingAs($this->staff)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'amount_paid' => 100,
            'walkin_customer_name' => 'Sam Lee',
        ]);

        $this->assertSame('Sam Lee', Order::sole()->walkin_customer_name);
    }

    public function test_an_empty_cart_cannot_be_checked_out(): void
    {
        // Rejected by request validation before the service is reached, so the
        // cashier gets a message on the payment field rather than a dead end.
        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), [
                'payment_method' => 'cash',
                'amount_paid' => 10,
            ])
            ->assertSessionHasErrors('payment_method');

        $this->assertSame(0, Order::count());
    }

    public function test_each_sale_gets_a_distinct_order_number(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 10]);

        foreach (range(1, 3) as $ignored) {
            $this->fillCart($product, 1);
            $this->actingAs($this->staff)->post(route('pos.checkout'), [
                'payment_method' => 'cash',
                'amount_paid' => 100,
            ]);
        }

        $numbers = Order::pluck('order_number');

        $this->assertCount(3, $numbers);
        $this->assertCount(3, $numbers->unique());
        $this->assertTrue($numbers->every(fn ($n) => str_starts_with($n, 'POS-')));
    }

    public function test_checkout_is_redirected_to_the_receipt(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 5]);
        $this->fillCart($product, 1);

        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), [
                'payment_method' => 'cash',
                'amount_paid' => 100,
            ])
            ->assertRedirect(route('pos.receipt', Order::sole()));
    }

    public function test_guests_cannot_reach_the_terminal(): void
    {
        $this->get(route('pos.index'))->assertRedirect(route('login'));
        $this->post(route('pos.checkout'), [])->assertRedirect(route('login'));
    }
}
