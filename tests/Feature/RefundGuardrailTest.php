<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\RefundNotificationStatus;
use App\Enums\RefundReason;
use App\Events\RefundProcessed;
use App\Listeners\SendRefundAlert;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Services\RefundNotifier;
use App\Services\RefundService;
use App\Support\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The guardrails that let a cashier refund with no manager on the premises.
 *
 * Each test here corresponds to one of the ways the feature could be abused by
 * accident or on purpose, and asserts the store is protected from it.
 */
class RefundGuardrailTest extends TestCase
{
    use RefreshDatabase;

    private function sell(User $cashier, Product $product, int $quantity = 4, string $taxRate = '0'): Order
    {
        Setting::setMany(['currency_symbol' => '₱', 'tax_rate' => $taxRate]);
        Setting::flushCache();

        // The till's cart lives in the session, so a previous sale in the same
        // test would otherwise bleed into this one.
        $this->actingAs($cashier)->delete(route('pos.cart.clear'));

        $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => $quantity]);

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 100000,
        ]);

        return Order::with('items')->latest()->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function refundPayload(Order $order, int $lineId, int $quantity, array $overrides = []): array
    {
        return array_merge([
            'reason_code' => RefundReason::Damaged->value,
            'method' => PaymentMethod::Cash->value,
            'items' => [$lineId => $quantity],
        ], $overrides);
    }

    // ---------------------------------------------------------------------
    // No arbitrary amounts
    // ---------------------------------------------------------------------

    public function test_the_amount_is_derived_from_the_lines_and_never_accepted_from_the_request(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 20)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 3);
        $line = $order->items->sole();

        // A caller who tries to name their own figure gets it ignored: the
        // server prices from the purchased line and nothing else.
        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1, [
                'amount' => 10000.00,
                'tax_amount' => 9999.00,
            ]))
            ->assertRedirect();

        $refund = Refund::sole();

        $this->assertEquals(20.00, (float) $refund->amount);
        $this->assertEquals(0.00, (float) $refund->tax_amount);
    }

    // ---------------------------------------------------------------------
    // Item-locked validation
    // ---------------------------------------------------------------------

    public function test_a_line_from_a_different_receipt_cannot_be_refunded(): void
    {
        $cashier = User::factory()->staff()->create();
        $cola = Product::factory()->priced(1, 5)->create(['stock' => 50]);
        $soap = Product::factory()->priced(1, 7)->create(['stock' => 50]);

        $orderA = $this->sell($cashier, $cola, quantity: 1);
        $orderB = $this->sell($cashier, $soap, quantity: 1);

        $this->assertEquals(49, $cola->fresh()->stock);
        $this->assertNotSame($orderA->id, $orderB->id);

        $foreignLine = $orderB->items->sole();

        $this->actingAs($cashier)
            ->post(route('refunds.store', $orderA), $this->refundPayload($orderA, $foreignLine->id, 1))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Refund::count());
        $this->assertSame(49, $cola->fresh()->stock, 'Stock must be untouched.');
        $this->assertSame(49, $soap->fresh()->stock);
    }

    // ---------------------------------------------------------------------
    // Anti-duplication
    // ---------------------------------------------------------------------

    public function test_the_same_receipt_cannot_be_spent_twice(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 5)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 2);
        $line = $order->items->sole();

        // Hand back both units.
        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 2))
            ->assertRedirect();

        // The receipt is now spent. Asking again must not pay out.
        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1))
            ->assertSessionHasErrors();

        $this->assertSame(1, Refund::count());
        $this->assertEquals(10.00, (float) $order->fresh()->refunded_amount);
        $this->assertSame(50, $product->fresh()->stock, 'Stock came back once, not twice.');
    }

    public function test_a_double_clicked_submit_pays_out_once(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 5)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 2);
        $line = $order->items->sole();

        // Both requests are the same intent and carry the same key, exactly as a
        // double click or a retried POST on a flaky connection would.
        $payload = $this->refundPayload($order, $line->id, 1, ['idempotency_key' => 'double-click-abc123']);

        $this->actingAs($cashier)->post(route('refunds.store', $order), $payload)->assertRedirect();
        $this->actingAs($cashier)->post(route('refunds.store', $order), $payload)->assertRedirect();

        $this->assertSame(1, Refund::count(), 'The replay must collapse onto the first refund.');
        $this->assertEquals(5.00, (float) $order->fresh()->refunded_amount);
        $this->assertSame(49, $product->fresh()->stock, 'Stock must only come back once.');
    }

    public function test_a_replayed_key_is_spent_once_even_against_another_order(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 5)->create(['stock' => 50]);

        $orderA = $this->sell($cashier, $product, quantity: 2);
        $orderB = $this->sell($cashier, $product, quantity: 2);

        $this->actingAs($cashier)->post(route('refunds.store', $orderA), $this->refundPayload(
            $orderA, $orderA->items->sole()->id, 1, ['idempotency_key' => 'shared-key-xyz']
        ))->assertRedirect();

        // The same key against a different receipt. A confused client or someone
        // probing should not be able to turn one key into two payouts.
        $this->actingAs($cashier)->post(route('refunds.store', $orderB), $this->refundPayload(
            $orderB, $orderB->items->sole()->id, 1, ['idempotency_key' => 'shared-key-xyz']
        ));

        $this->assertSame(1, Refund::count());
        $this->assertEquals(5.00, (float) $orderA->fresh()->refunded_amount);
        $this->assertEquals(0.00, (float) $orderB->fresh()->refunded_amount);
    }

    // ---------------------------------------------------------------------
    // Tax-inclusive pricing
    // ---------------------------------------------------------------------

    public function test_a_refund_returns_the_tax_the_customer_actually_paid(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 20)->create(['stock' => 50]);

        // 10% of 100.00 = 10.00 tax, 110.00 total.
        $order = $this->sell($cashier, $product, quantity: 5, taxRate: '10');
        $line = $order->items->sole();

        $this->assertEquals(110.00, (float) $order->total);
        $this->assertEquals(10.00, (float) $line->tax_amount);

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1))
            ->assertRedirect();

        $refund = Refund::sole();

        // 1 of 5 units: 22.00 back, of which 2.00 is tax.
        $this->assertEquals(22.00, (float) $refund->amount);
        $this->assertEquals(2.00, (float) $refund->tax_amount);
    }

    public function test_refunding_an_order_in_full_nets_to_exactly_zero(): void
    {
        Setting::setMany(['currency_symbol' => '₱', 'tax_rate' => '12']);
        Setting::flushCache();

        $cashier = User::factory()->staff()->create();
        $cola = Product::factory()->priced(1, 3.33)->create(['stock' => 50]);
        $soap = Product::factory()->priced(2, 7.77)->create(['stock' => 50]);

        // Odd unit prices and a 12% rate, spread over two lines in one order, so
        // per-unit rounding cannot be relied on to land on the right figure.
        $this->actingAs($cashier)->delete(route('pos.cart.clear'));
        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $cola->id, 'quantity' => 3]);
        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $soap->id, 'quantity' => 3]);
        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 100000,
        ]);

        $order = Order::with('items')->latest()->first();

        $this->assertCount(2, $order->items);
        // 3x3.33 + 3x7.77 = 33.30, +12% tax = 37.30.
        $this->assertEquals(37.30, (float) $order->total);

        $this->actingAs($cashier)->post(route('refunds.store', $order), [
            'reason_code' => RefundReason::ChangedMind->value,
            'method' => PaymentMethod::Cash->value,
            'items' => $order->items->mapWithKeys(fn ($i) => [$i->id => $i->quantity])->all(),
        ])->assertRedirect();

        $order->refresh();

        $this->assertTrue($order->isFullyRefunded());
        $this->assertEquals(
            (float) $order->total,
            round((float) $order->refunded_amount, 2),
            'A full refund returns exactly what was charged, to the cent.',
        );
        $this->assertEquals(0.0, $order->netRevenue());
        $this->assertEquals(0.0, $order->net_total);
    }

    public function test_refunding_the_same_line_twice_never_overpays(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 3.33)->create(['stock' => 50]);

        // Odd unit price and a 12% rate, so per-unit rounding cannot be relied on.
        $order = $this->sell($cashier, $product, quantity: 3, taxRate: '12');
        $line = $order->items->sole();

        // Two units now, the third after.
        $this->actingAs($cashier)->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 2))->assertRedirect();
        $this->actingAs($cashier)->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1))->assertRedirect();

        $this->assertEquals(2, Refund::count());
        $this->assertEquals(
            (float) $order->total,
            round((float) $order->fresh()->refunded_amount, 2),
            'Split refunds must add back up to the order total, to the cent.',
        );
    }

    public function test_a_tax_rate_change_does_not_rewrite_old_refund_prices(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 10)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 2, taxRate: '10');

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $order->items->sole()->id, 1))
            ->assertRedirect();

        // Double the tax rate, then refund the other unit.
        Setting::setMany(['tax_rate' => '20']);
        Setting::flushCache();

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $order->items->sole()->id, 1))
            ->assertRedirect();

        // Both units were sold at 10%, so both refunds are 11.00, and the order
        // nets to zero regardless of what the rate says now.
        $this->assertEquals(
            (float) $order->total,
            round((float) $order->fresh()->refunded_amount, 2),
        );
        $this->assertEquals(0.0, $order->fresh()->net_total);
    }

    // ---------------------------------------------------------------------
    // Review threshold
    // ---------------------------------------------------------------------

    public function test_a_refund_over_the_threshold_is_flagged_but_still_goes_through(): void
    {
        Setting::setMany(['refund_review_threshold' => '500']);
        Setting::flushCache();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 400)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 2);
        $line = $order->items->sole();

        // 800.00 - the store must not stall, so this is processed immediately.
        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 2))
            ->assertRedirect();

        $refund = Refund::sole();

        $this->assertTrue($refund->review_required);
        $this->assertEquals(800.00, (float) $refund->amount);
        $this->assertNull($refund->reviewed_at);
    }

    public function test_a_refund_at_or_under_the_threshold_is_not_flagged(): void
    {
        Setting::setMany(['refund_review_threshold' => '500']);
        Setting::flushCache();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 250)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 2);
        $line = $order->items->sole();

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 2))
            ->assertRedirect();

        // Exactly 500.00 is "at", which is under the flag.
        $this->assertEquals(500.00, (float) Refund::sole()->amount);
        $this->assertFalse(Refund::sole()->review_required);
    }

    public function test_the_owner_can_sign_off_a_flagged_refund(): void
    {
        Setting::setMany(['refund_review_threshold' => '10']);
        Setting::flushCache();

        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 100)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 1);
        $line = $order->items->sole();

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1));

        $refund = Refund::sole();
        $this->assertTrue($refund->review_required);

        $this->actingAs($admin)->post(route('admin.refunds.review', $refund))->assertRedirect();

        $refund->refresh();
        $this->assertTrue($refund->isReviewed());
        $this->assertSame($admin->id, $refund->reviewed_by);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::REFUND_REVIEWED]);

        // Reviewing twice is refused rather than silently overwriting.
        $this->actingAs($admin)->post(route('admin.refunds.review', $refund))->assertStatus(422);
    }

    public function test_a_cashier_cannot_sign_off_their_own_refund(): void
    {
        Setting::setMany(['refund_review_threshold' => '10']);
        Setting::flushCache();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 100)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 1);
        $line = $order->items->sole();

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1));

        $refund = Refund::sole();

        $this->actingAs($cashier)
            ->post(route('admin.refunds.review', $refund))
            ->assertRedirect(route('pos.index'));

        $this->assertNull($refund->fresh()->reviewed_at);
    }

    // ---------------------------------------------------------------------
    // Daily ceiling
    // ---------------------------------------------------------------------

    public function test_a_cashier_is_capped_for_the_day(): void
    {
        Setting::setMany(['refund_daily_limit' => '100']);
        Setting::flushCache();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 60)->create(['stock' => 100]);
        $order = $this->sell($cashier, $product, quantity: 4);
        $line = $order->items->sole();

        // 60.00 - inside the ceiling.
        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1))
            ->assertRedirect();

        // Another 60.00 would take them to 120.00, over the 100.00 ceiling.
        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1))
            ->assertSessionHas('error');

        $this->assertSame(1, Refund::count());
        $this->assertEquals(60.00, (float) $order->fresh()->refunded_amount);
    }

    public function test_the_daily_ceiling_is_per_cashier(): void
    {
        Setting::setMany(['refund_daily_limit' => '100']);
        Setting::flushCache();

        $alice = User::factory()->staff()->create();
        $bruno = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 60)->create(['stock' => 100]);

        $orderA = $this->sell($alice, $product, quantity: 4);
        $this->actingAs($alice)
            ->post(route('refunds.store', $orderA), $this->refundPayload($orderA, $orderA->items->sole()->id, 1))
            ->assertRedirect();

        // Bruno has spent nothing, so he is unaffected by Alice running out.
        $orderB = $this->sell($bruno, $product, quantity: 4);
        $this->actingAs($bruno)
            ->post(route('refunds.store', $orderB), $this->refundPayload($orderB, $orderB->items->sole()->id, 1))
            ->assertRedirect();

        $this->assertSame(2, Refund::count());
    }

    public function test_the_owner_is_exempt_from_the_daily_ceiling(): void
    {
        Setting::setMany(['refund_daily_limit' => '10']);
        Setting::flushCache();

        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 60)->create(['stock' => 100]);

        // The owner never rings up sales, so the sale is a cashier's and the
        // refund is the owner's.
        $order = $this->sell($cashier, $product, quantity: 4);

        $this->actingAs($admin)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $order->items->sole()->id, 3))
            ->assertRedirect();

        $this->assertSame(1, Refund::count());
        $this->assertSame($admin->id, Refund::sole()->user_id);
    }

    public function test_the_ceiling_is_off_by_default(): void
    {
        Setting::setMany(['refund_daily_limit' => '0']);
        Setting::flushCache();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 60)->create(['stock' => 200]);

        $order = $this->sell($cashier, $product, quantity: 4);
        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $order->items->sole()->id, 2))
            ->assertRedirect();

        $this->assertEquals(1, Refund::count());
    }

    // ---------------------------------------------------------------------
    // Reason capture
    // ---------------------------------------------------------------------

    public function test_the_other_reason_cannot_be_used_without_an_explanation(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 10)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 2);
        $line = $order->items->sole();

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1, [
                'reason_code' => RefundReason::Other->value,
            ]))
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, Refund::count());

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1, [
                'reason_code' => RefundReason::Other->value,
                'reason' => 'Customer was a long-time regular, manager said okay',
            ]))
            ->assertRedirect();

        $this->assertSame(1, Refund::count());
    }

    public function test_a_known_reason_does_not_need_a_written_explanation(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 10)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 2);
        $line = $order->items->sole();

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $refund = Refund::sole();
        $this->assertSame('damaged', $refund->reason_code->value);
        $this->assertNull($refund->reason);
    }

    public function test_an_unknown_reason_code_is_rejected(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 10)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 2);
        $line = $order->items->sole();

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1, [
                'reason_code' => 'because-i-said-so',
            ]))
            ->assertSessionHasErrors('reason_code');

        $this->assertSame(0, Refund::count());
    }

    // ---------------------------------------------------------------------
    // Notification
    // ---------------------------------------------------------------------

    public function test_a_refund_raises_an_alert_event(): void
    {
        Event::fake([RefundProcessed::class]);

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 10)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 2);
        $line = $order->items->sole();

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1))
            ->assertRedirect();

        // Raised only after the transaction commits, so an alert can never
        // describe a refund that was rolled back.
        Event::assertDispatched(RefundProcessed::class);

        // The outstanding alert is recorded even though the listener never ran,
        // so a stopped queue worker is visible instead of silent.
        $this->assertDatabaseHas('refund_notifications', [
            'refund_id' => Refund::sole()->id,
            'channel' => RefundService::ALERT_CHANNEL,
            'status' => RefundNotificationStatus::Pending->value,
        ]);
    }

    public function test_the_alert_listener_is_queued_so_a_slow_telegram_cannot_block_the_till(): void
    {
        // A structural assertion rather than a behavioural one: the suite pins
        // QUEUE_CONNECTION=sync so the queue cannot be observed from a test, but
        // the guarantee the store depends on is the contract itself - the alert
        // must never run inside the cashier's request.
        $this->assertInstanceOf(
            ShouldQueue::class,
            new SendRefundAlert,
            'The refund alert must be queued, not sent inline.',
        );

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 10)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 2);
        $line = $order->items->sole();

        // And the refund completes regardless of whether the alert can be sent.
        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1))
            ->assertRedirect();

        $this->assertSame(1, Refund::count());
    }

    public function test_the_alert_reaches_telegram_with_the_cashier_order_reason_and_amount(): void
    {
        Setting::setMany(['telegram_bot_token' => '123:ABC', 'telegram_chat_id' => '999']);
        Setting::flushCache();

        Http::fake();

        $cashier = User::factory()->staff()->create(['name' => 'Rosa']);
        $product = Product::factory()->priced(1, 75)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 1);
        $line = $order->items->sole();

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1))
            ->assertRedirect();

        $refund = Refund::sole();
        app(RefundNotifier::class)->send($refund);

        Http::assertSent(function ($request) use ($order, $refund) {
            $body = $request->data();

            return str_contains($request->url(), 'api.telegram.org')
                && $body['chat_id'] === '999'
                && str_contains($body['text'], 'Rosa')
                && str_contains($body['text'], $order->order_number)
                && str_contains($body['text'], $refund->refund_number)
                && str_contains($body['text'], '75.00')
                && str_contains($body['text'], RefundReason::Damaged->label());
        });

        $this->assertSame(RefundNotificationStatus::Sent, $refund->notifications()->sole()->status);
    }

    public function test_a_failed_alert_is_recorded_rather_than_lost(): void
    {
        Setting::setMany(['telegram_bot_token' => '123:ABC', 'telegram_chat_id' => '999']);
        Setting::flushCache();

        Http::fake(fn () => Http::response(['description' => 'Bad Request: chat not found'], 400));

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 10)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 1);
        $line = $order->items->sole();

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1));

        $notification = app(RefundNotifier::class)->send(Refund::sole());

        $this->assertSame(RefundNotificationStatus::Failed, $notification->status);
        $this->assertStringContainsString('chat not found', (string) $notification->error);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::REFUND_ALERT_FAILED]);
    }

    public function test_an_unconfigured_telegram_is_reported_rather_than_thrown(): void
    {
        Http::fake();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 10)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 1);
        $line = $order->items->sole();

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1))
            ->assertRedirect();

        $notification = app(RefundNotifier::class)->send(Refund::sole());

        $this->assertSame(RefundNotificationStatus::Failed, $notification->status);
        $this->assertStringContainsString('not configured', (string) $notification->error);
        // The refund itself is unaffected by the alert failing.
        $this->assertSame(1, Refund::count());
    }

    public function test_a_replayed_alert_does_not_message_the_owner_twice(): void
    {
        Setting::setMany(['telegram_bot_token' => '123:ABC', 'telegram_chat_id' => '999']);
        Setting::flushCache();

        Http::fake();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 10)->create(['stock' => 50]);
        $order = $this->sell($cashier, $product, quantity: 1);
        $line = $order->items->sole();

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), $this->refundPayload($order, $line->id, 1));

        $refund = Refund::sole();
        $notifier = app(RefundNotifier::class);

        $notifier->send($refund);
        $notifier->send($refund);

        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------------
    // Reconciliation report
    // ---------------------------------------------------------------------

    public function test_the_reconciliation_report_shows_expected_drawer_per_cashier(): void
    {
        $admin = User::factory()->admin()->create();
        $rosa = User::factory()->staff()->create(['name' => 'Rosa']);
        $bruno = User::factory()->staff()->create(['name' => 'Bruno']);

        $product = Product::factory()->priced(1, 10)->create(['stock' => 200]);

        // Rosa takes 100.00 of cash and hands 30.00 of it back.
        $rosaOrder = $this->sell($rosa, $product, quantity: 10);
        $this->actingAs($rosa)->post(route('refunds.store', $rosaOrder), $this->refundPayload(
            $rosaOrder, $rosaOrder->items->sole()->id, 3
        ))->assertRedirect();

        $this->sell($bruno, $product, quantity: 4);

        $rows = $this->actingAs($admin)
            ->get(route('admin.reports.refunds'))
            ->assertOk()
            ->viewData('byCashier');

        $rosaRow = $rows->firstWhere('name', 'Rosa');
        $brunoRow = $rows->firstWhere('name', 'Bruno');

        $this->assertEquals(100.00, $rosaRow->cash_sales);
        $this->assertEquals(30.00, $rosaRow->cash_out);
        $this->assertEquals(70.00, $rosaRow->expected_cash, 'What should be left in the drawer.');
        $this->assertEquals(30.00, $rosaRow->refund_rate);

        $this->assertEquals(40.00, $brunoRow->cash_sales);
        $this->assertEquals(0.00, $brunoRow->cash_out);
        $this->assertEquals(0.0, $brunoRow->refund_rate);
    }

    public function test_a_gcash_refund_does_not_come_out_of_the_cash_drawer(): void
    {
        $admin = User::factory()->admin()->create();
        $rosa = User::factory()->staff()->create(['name' => 'Rosa']);
        $product = Product::factory()->priced(1, 10)->create(['stock' => 200]);

        $order = $this->sell($rosa, $product, quantity: 10);
        $this->actingAs($rosa)->post(route('refunds.store', $order), $this->refundPayload(
            $order, $order->items->sole()->id, 5, ['method' => PaymentMethod::Gcash->value]
        ))->assertRedirect();

        $row = $this->actingAs($admin)
            ->get(route('admin.reports.refunds'))
            ->viewData('byCashier')
            ->firstWhere('name', 'Rosa');

        $this->assertEquals(50.00, $row->refunded, 'Money still went back to the customer.');
        $this->assertEquals(0.00, $row->cash_out, 'But it never left the cash drawer.');
        $this->assertEquals(100.00, $row->expected_cash);
    }

    public function test_the_reconciliation_report_exports_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $rosa = User::factory()->staff()->create(['name' => 'Rosa']);
        $product = Product::factory()->priced(1, 10)->create(['stock' => 50]);
        $this->sell($rosa, $product, quantity: 5);

        $response = $this->actingAs($admin)->get(route('admin.reports.refunds', ['export' => 1]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Rosa', $response->streamedContent());
    }

    public function test_the_reconciliation_report_is_admin_only(): void
    {
        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)
            ->get(route('admin.reports.refunds'))
            ->assertRedirect(route('pos.index'));
    }

    public function test_the_refund_listing_stays_admin_only(): void
    {
        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)
            ->get(route('admin.refunds.index'))
            ->assertRedirect(route('pos.index'));
    }
}
