<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The senior citizen / PWD discount is a legal entitlement, not a promotion, and
 * that shapes every decision here: the rate belongs to the store, the cashier
 * cannot set it, and the claim is recorded so it can be checked later.
 */
class ScpwdDiscountTest extends TestCase
{
    use RefreshDatabase;

    private function seedSettings(array $overrides = []): void
    {
        Setting::setMany(array_merge([
            'store_name' => '88minimart',
            'currency_symbol' => '₱',
            'tax_rate' => '0',
            'scpwd_discount_rate' => '20',
        ], $overrides));
        Setting::flushCache();
    }

    private function ringUp(float $price, int $quantity = 1): Product
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, $price)->create(['stock' => 50]);

        $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => $quantity]);

        return $product;
    }

    public function test_a_claimed_discount_is_taken_off_the_bill(): void
    {
        $this->seedSettings();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, 100.00)->create(['stock' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'paid_amount' => 80.00,
            'scpwd' => 1,
            'scpwd_name' => 'Rosa Dela Cruz',
            'scpwd_id_type' => 'Senior Citizen ID',
            'scpwd_id_number' => '1234-5678-9012',
        ]);

        $order = Order::sole();

        $this->assertTrue($order->scpwd_applied);
        $this->assertEquals(20.00, (float) $order->discount_amount);
        $this->assertEquals(100.00, (float) $order->subtotal);
        $this->assertEquals(80.00, (float) $order->total);
        $this->assertEquals(80.00, (float) $order->paid_amount);
        $this->assertEquals(0.00, (float) $order->change_amount);

        // The evidence is what makes the claim checkable.
        $this->assertSame('Rosa Dela Cruz', $order->scpwd_name);
        $this->assertSame('Senior Citizen ID', $order->scpwd_id_type);
        $this->assertSame('1234-5678-9012', $order->scpwd_id_number);
    }

    public function test_a_claim_without_an_id_is_refused(): void
    {
        $this->seedSettings();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, 100.00)->create(['stock' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'paid_amount' => 100.00,
            'scpwd' => 1,
        ])->assertSessionHasErrors(['scpwd_name', 'scpwd_id_type', 'scpwd_id_number']);

        $this->assertSame(0, Order::count());
    }

    public function test_an_invented_id_type_is_refused(): void
    {
        $this->seedSettings();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, 100.00)->create(['stock' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'paid_amount' => 100.00,
            'scpwd' => 1,
            'scpwd_name' => 'Rosa Dela Cruz',
            'scpwd_id_type' => 'My Word',
            'scpwd_id_number' => '1234',
        ])->assertSessionHasErrors('scpwd_id_type');

        $this->assertSame(0, Order::count());
    }

    public function test_the_rate_is_never_taken_from_the_request(): void
    {
        $this->seedSettings(['scpwd_discount_rate' => '20']);
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, 100.00)->create(['stock' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

        // A client asking for a 100% discount, or for its own rate field, gets
        // the store's rate or nothing.
        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'paid_amount' => 100.00,
            'scpwd' => 1,
            'scpwd_name' => 'Rosa Dela Cruz',
            'scpwd_id_type' => 'PWD ID',
            'scpwd_id_number' => '9999',
            'scpwd_discount_rate' => 100,
            'discount_amount' => 100,
        ]);

        $order = Order::sole();

        $this->assertEquals(20.00, (float) $order->discount_amount);
        $this->assertEquals(80.00, (float) $order->total);
    }

    public function test_a_store_that_switches_the_discount_off_never_grants_one(): void
    {
        $this->seedSettings(['scpwd_discount_rate' => '0']);
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, 100.00)->create(['stock' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'paid_amount' => 100.00,
            'scpwd' => 1,
            'scpwd_name' => 'Rosa Dela Cruz',
            'scpwd_id_type' => 'PWD ID',
            'scpwd_id_number' => '9999',
        ]);

        $order = Order::sole();

        $this->assertFalse($order->scpwd_applied);
        $this->assertEquals(0.00, (float) $order->discount_amount);
        $this->assertEquals(100.00, (float) $order->total);
        // No claim is stored when no discount was granted, so a 0% claim is
        // distinguishable from a real one.
        $this->assertNull($order->scpwd_name);
    }

    public function test_a_claim_never_makes_the_bill_negative(): void
    {
        $this->seedSettings(['scpwd_discount_rate' => '100']);
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, 100.00)->create(['stock' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'paid_amount' => 0,
            'scpwd' => 1,
            'scpwd_name' => 'Rosa Dela Cruz',
            'scpwd_id_type' => 'PWD ID',
            'scpwd_id_number' => '9999',
        ]);

        $order = Order::sole();

        $this->assertEquals(100.00, (float) $order->discount_amount);
        $this->assertEquals(0.00, (float) $order->total);
    }

    public function test_the_till_offers_no_discount_path_outside_the_claim(): void
    {
        $this->seedSettings();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, 100.00)->create(['stock' => 10]);

        // There is still no cart-level discount: the cart remains subtotal + tax,
        // and the claim is made at payment only.
        $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertJsonPath('totals.total', 100)
            ->assertJsonPath('totals.discount_amount', null);

        $this->assertFalse(Route::has('pos.cart.discount'));

        $this->actingAs($cashier)->get(route('pos.index'))->assertDontSee('apply-discount', false);
    }

    public function test_a_sale_with_no_claim_records_no_discount(): void
    {
        $this->seedSettings();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, 100.00)->create(['stock' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'paid_amount' => 100.00,
        ]);

        $order = Order::sole();

        $this->assertFalse($order->scpwd_applied);
        $this->assertEquals(0.00, (float) $order->discount_amount);
        $this->assertEquals(100.00, (float) $order->total);
    }

    public function test_the_receipt_prints_the_discount_and_the_claim(): void
    {
        $this->seedSettings();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, 100.00)->create(['stock' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'paid_amount' => 80.00,
            'scpwd' => 1,
            'scpwd_name' => 'Rosa Dela Cruz',
            'scpwd_id_type' => 'Senior Citizen ID',
            'scpwd_id_number' => '1234-5678-9012',
        ]);

        $order = Order::sole();

        $this->actingAs($cashier)
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee('Senior Citizen')
            ->assertSee('Rosa Dela Cruz')
            ->assertSee('1234-5678-9012');
    }

    public function test_a_gcash_reference_is_kept(): void
    {
        $this->seedSettings();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, 100.00)->create(['stock' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Gcash->value,
            'paid_amount' => 100.00,
            'gcash_reference' => 'GC-88441220',
        ]);

        $order = Order::sole();

        $this->assertSame('GC-88441220', $order->gcash_reference);
        $this->assertSame(PaymentMethod::Gcash, $order->payment_method);
    }

    public function test_a_cash_sale_never_carries_a_gcash_reference(): void
    {
        $this->seedSettings();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(0, 100.00)->create(['stock' => 10]);

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

        // A reference on a cash sale would be a reconciliation lie.
        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => 'cash',
            'paid_amount' => 100.00,
            'gcash_reference' => 'GC-SHOULD-NOT-STICK',
        ]);

        $this->assertNull(Order::sole()->gcash_reference);
    }
}
