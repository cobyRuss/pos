<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\PaymentMethod;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\CartService;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function seedSettings(): void
    {
        Setting::setMany([
            'store_name' => 'Test Store',
            'currency_symbol' => '$',
            'tax_rate' => '10',
        ]);
        Setting::flushCache();
    }

    public function test_the_till_only_offers_cash_and_gcash(): void
    {
        $this->assertSame(['cash', 'gcash'], PaymentMethod::values());

        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)
            ->get(route('pos.index'))
            ->assertSee('GCash (E-Wallet)')
            ->assertDontSee('Card', false)
            ->assertDontSee('Mobile / E-Wallet', false)
            ->assertDontSee('Other', false);
    }

    public function test_a_sale_can_be_paid_with_gcash(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(5.00, 10.00)->create(['stock' => 25]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Gcash->value,
            'paid_amount' => 22.00,
        ]);

        $order = Order::sole();

        $this->assertSame(PaymentMethod::Gcash, $order->payment_method);
        $this->assertEquals(22.00, (float) $order->total);
        $this->assertEquals(0.00, (float) $order->change_amount);
    }

    public function test_a_removed_payment_method_is_rejected(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(5.00, 10.00)->create(['stock' => 25]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

        foreach (['card', 'mobile', 'other'] as $method) {
            $this->actingAs($cashier)
                ->post(route('pos.checkout'), ['payment_method' => $method, 'paid_amount' => 100.00])
                ->assertSessionHasErrors('payment_method');
        }

        $this->assertSame(0, Order::count());
    }

    public function test_a_cashier_can_complete_a_sale_and_stock_is_reduced(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(5.00, 10.00)->create(['stock' => 25, 'low_stock_threshold' => 5]);

        $response = $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 3]);

        $response->assertOk()->assertJsonFragment(['message' => $product->name.' added to cart.']);

        $checkout = $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 40.00,
        ]);

        $order = Order::sole();

        // 3 x 10.00 = 30.00 subtotal + 10% tax = 33.00 total, 7.00 change.
        $checkout->assertRedirect(route('orders.receipt', $order));
        $checkout->assertSessionHas('success');

        $this->assertSame('completed', $order->status->value);
        $this->assertEquals(30.00, (float) $order->subtotal);
        $this->assertEquals(3.00, (float) $order->tax_amount);
        $this->assertEquals(33.00, (float) $order->total);
        $this->assertEquals(40.00, (float) $order->paid_amount);
        $this->assertEquals(7.00, (float) $order->change_amount);
        $this->assertSame($cashier->id, $order->user_id);

        $this->assertSame(22, $product->fresh()->stock);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseHas('order_items', ['product_id' => $product->id, 'quantity' => 3]);

        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'type' => InventoryMovementType::Sale->value,
            'quantity' => -3,
            'before_stock' => 25,
            'after_stock' => 22,
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::ORDER_CREATED]);
    }

    public function test_the_cart_is_emptied_after_checkout(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Gcash->value,
            'paid_amount' => 100,
        ]);

        $this->assertSame(0, app(CartService::class)->count());
    }

    public function test_the_till_offers_no_promos_and_totals_subtotal_plus_tax(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, 20.00)->create(['stock' => 10]);

        // Clicking a product returns the live totals straight away: no Apply
        // step, and no discount keys in the payload at all.
        $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 2])
            ->assertJsonPath('totals.total', 44)
            ->assertJsonPath('totals.discount_amount', null)
            ->assertJsonPath('totals.discount_type', null);

        $this->assertFalse(Route::has('pos.cart.discount'));

        $this->actingAs($cashier)->get(route('pos.index'))->assertDontSee('apply-discount', false);

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 44,
        ]);

        $order = Order::sole();

        // 40.00 subtotal + 10% tax = 44.00, nothing discounted off the top.
        $this->assertEquals(40.00, (float) $order->subtotal);
        $this->assertEquals(0.00, (float) $order->discount_amount);
        $this->assertEquals(44.00, (float) $order->total);
    }

    public function test_checkout_is_blocked_when_the_tendered_amount_is_short(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, 20.00)->create(['stock' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($cashier)
            ->post(route('pos.checkout'), [
                'payment_method' => PaymentMethod::Cash->value,
                'paid_amount' => 10,
            ])
            ->assertSessionHasErrors('paid_amount');

        $this->assertSame(0, Order::count());
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_checkout_with_an_empty_cart_is_rejected(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)
            ->post(route('pos.checkout'), [
                'payment_method' => PaymentMethod::Cash->value,
                'paid_amount' => 10,
            ])
            ->assertSessionHasErrors('items');
    }

    public function test_a_product_cannot_be_added_beyond_available_stock(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->create(['stock' => 2]);

        $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 5])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Only 2 unit(s) of "'.$product->name.'" left in stock.']);
    }

    public function test_inactive_products_cannot_be_added_to_the_cart(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->inactive()->create(['stock' => 50]);

        $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertStatus(422);
    }

    public function test_stock_that_vanishes_after_adding_to_the_cart_blocks_checkout(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, 5.00)->create(['stock' => 5]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 5]);

        // Another till sells the last units before this cashier checks out.
        $product->forceFill(['stock' => 1])->save();

        $this->actingAs($cashier)
            ->post(route('pos.checkout'), [
                'payment_method' => PaymentMethod::Cash->value,
                'paid_amount' => 50,
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Order::count());
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_the_receipt_renders_for_the_cashier(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(2, 4)->create(['stock' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 2]);
        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 10,
        ]);

        $order = Order::sole();

        $this->actingAs($cashier)
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee($product->name);
    }

    public function test_cart_endpoints_update_and_clear_the_cart(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($cashier)->patchJson(route('pos.cart.update', $product->id), ['quantity' => 4])->assertOk();
        $this->assertSame(4, app(CartService::class)->totalQuantity());

        $this->actingAs($cashier)->deleteJson(route('pos.cart.destroy', $product->id))->assertOk();
        $this->assertSame(0, app(CartService::class)->count());

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($cashier)->deleteJson(route('pos.cart.clear'))->assertOk();
        $this->assertSame(0, app(CartService::class)->count());
    }

    public function test_pos_search_only_returns_active_products(): void
    {
        $cashier = User::factory()->staff()->create();
        $visible = Product::factory()->create(['name' => 'Blue Widget', 'stock' => 5]);
        Product::factory()->inactive()->create(['name' => 'Blue Widget (discontinued)', 'stock' => 5]);
        Product::factory()->create(['name' => 'Red Gadget', 'stock' => 5]);

        $response = $this->actingAs($cashier)->getJson(route('pos.search', ['q' => 'Blue']));

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($visible->id, $response->json('data.0.id'));
    }

    public function test_inventory_movements_are_attributed_to_the_cashier(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, 3.00)->create(['stock' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 4,
        ]);

        $movement = InventoryMovement::where('type', InventoryMovementType::Sale->value)->sole();

        $this->assertSame($cashier->id, $movement->user_id);
        $this->assertSame(Order::sole()->getKey(), $movement->reference_id);
    }
}
