<?php

namespace Tests\Feature;

use App\Models\CashSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\RefundNotification;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The store is in a province and its connection is not dependable. Selling must
 * not care, because a customer standing at the counter cannot be told to wait
 * for a cable to come back.
 *
 * That guarantee is architectural, not a feature that can be toggled: the app is
 * a local XAMPP install talking to a local MySQL, so the sale path has no
 * network dependency to lose. The only outbound calls in the entire codebase are
 * the two Telegram notifiers, and both are queued and non-blocking.
 *
 * These tests exist to make that structural fact explicit, so a future change
 * that quietly puts a remote call on the checkout path fails here rather than in
 * a province at 7pm.
 */
class OfflineResilienceTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): void
    {
        // No tax in this file on purpose. None of these tests are about tax
        // arithmetic - RefundTest and CheckoutTest already cover that - and a
        // tax rate would quietly turn every expected peso figure into a
        // different one.
        Setting::setMany(['store_name' => '88minimart', 'currency_symbol' => '₱', 'tax_rate' => '0']);
        Setting::flushCache();
    }

    public function test_a_full_sale_makes_no_outbound_request(): void
    {
        $this->settings();
        Http::fake();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(5, 12.50)->create(['stock' => 20]);

        $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 3])
            ->assertOk();

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => 'gcash',
            'paid_amount' => 200,
            'gcash_reference' => 'GC-0001',
        ])->assertRedirect();

        // If anything on the checkout path had tried to reach the internet, this
        // is where it would show up.
        Http::assertNothingSent();
    }

    public function test_the_till_renders_with_every_asset_served_from_disk(): void
    {
        $this->settings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->create(['name' => 'Cola Can']);

        $html = $this->actingAs($cashier)->get(route('pos.index'))->assertOk()->getContent();

        // The page must not reach out for a stylesheet or a script. A CDN link
        // here is what stops the Take Payment modal from opening when the
        // connection drops, which is the same as being unable to sell.
        $this->assertStringNotContainsString('cdn.jsdelivr', $html);
        $this->assertStringNotContainsString('//cdn.', $html);
        $this->assertStringNotContainsString('unpkg.com', $html);
        $this->assertStringNotContainsString('cdnjs.', $html);

        // Everything the till needs is a local asset path.
        $this->assertStringContainsString('/vendor/bootstrap-5.3.3/bootstrap.min.css', $html);
        $this->assertStringContainsString('/vendor/bootstrap-5.3.3/bootstrap.bundle.min.js', $html);
        $this->assertStringContainsString('/vendor/html5-qrcode/html5-qrcode.min.js', $html);
    }

    public function test_the_receipt_renders_with_local_assets_only(): void
    {
        $this->settings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 5)->create();

        $order = app(OrderService::class)->checkout(
            ['items' => [$product->id => ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5]]],
            ['paid_amount' => 5, 'payment_method' => 'cash'],
            $cashier,
        );

        $html = $this->actingAs($cashier)->get(route('orders.receipt', $order))->assertOk()->getContent();

        // A receipt has to print at the counter whatever else is true that day.
        $this->assertStringNotContainsString('cdn.jsdelivr', $html);
        $this->assertStringContainsString('/vendor/bootstrap-5.3.3/bootstrap.min.css', $html);
    }

    public function test_a_refund_still_completes_when_telegram_is_unreachable(): void
    {
        $this->settings();

        // The hard case: no network at all. Telegram is not configured, so the
        // notifier gives up immediately - but the refund itself must still be
        // recorded, restocked and reported on.
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 5)->create(['stock' => 10]);

        $order = app(OrderService::class)->checkout(
            ['items' => [$product->id => ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 5]]],
            ['paid_amount' => 10, 'payment_method' => 'cash'],
            $cashier,
        );

        $this->actingAs($cashier)->post(route('refunds.store', $order), [
            'items' => [$order->items->first()->id => 1],
            'method' => 'cash',
            'reason_code' => 'damaged',
            'idempotency_key' => 'offline-refund-key',
        ])->assertRedirect();

        $refund = $order->refresh()->refunds()->sole();

        $this->assertEquals(5.00, (float) $refund->amount);

        // The failure is recorded rather than swallowed, so the owner can see
        // that the alert never left the building.
        $notification = RefundNotification::where('refund_id', $refund->id)->sole();
        $this->assertNotNull($notification->error);

        // And the stock is back on the shelf.
        $this->assertEquals(9, (int) $product->refresh()->stock);
    }

    public function test_an_undelivered_alert_is_recoverable_after_the_connection_returns(): void
    {
        $this->settings();

        // This is the "sync when the internet comes back" behaviour. Nothing is
        // queued in the browser and nothing has to be replayed by hand: the
        // pending notification is a database row, and a retry command walks it.
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 5)->create(['stock' => 10]);

        $order = app(OrderService::class)->checkout(
            ['items' => [$product->id => ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5]]],
            ['paid_amount' => 5, 'payment_method' => 'cash'],
            $cashier,
        );

        $this->actingAs($cashier)->post(route('refunds.store', $order), [
            'items' => [$order->items->first()->id => 1],
            'method' => 'cash',
            'reason_code' => 'damaged',
            'idempotency_key' => 'offline-refund-key-2',
        ])->assertRedirect();

        $refund = $order->refresh()->refunds()->sole();
        $notification = RefundNotification::where('refund_id', $refund->id)->sole();

        $this->assertNotNull($notification->error, 'The undelivered alert should be on record.');

        // The connection is back and the token is configured: the same command
        // now delivers the alert that was stranded.
        Setting::setMany([
            'telegram_bot_token' => '123456789:AAExampleTokenValue',
            'telegram_chat_id' => '-1001234567890',
        ]);
        Setting::flushCache();

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->artisan('refund:retry-notifications --pending')->assertSuccessful();

        $notification->refresh();
        $this->assertEquals('sent', $notification->status->value);
    }

    public function test_a_drawer_can_be_closed_with_no_connection(): void
    {
        $this->settings();
        Http::fake();

        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)->post(route('pos.drawer.open'), ['opening_float' => 500]);
        $session = CashSession::sole();
        $this->sell($cashier, 200.00);

        $this->actingAs($cashier)
            ->post(route('pos.drawer.close', $session), ['counted_cash' => 700])
            ->assertRedirect();

        $this->assertEquals(0.00, $session->refresh()->variance());
        Http::assertNothingSent();
    }

    private function sell(User $cashier, float $amount): Order
    {
        $product = Product::factory()->priced(0, $amount)->create(['stock' => 50]);

        return app(OrderService::class)->checkout(
            ['items' => [$product->id => ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => $amount]]],
            ['paid_amount' => $amount, 'payment_method' => 'cash'],
            $cashier,
        );
    }
}
