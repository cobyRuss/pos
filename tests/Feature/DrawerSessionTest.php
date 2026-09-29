<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Events\DayClosed;
use App\Models\CashSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The drawer is the only figure in this system that comes from physically
 * counting money rather than from arithmetic on rows, so these tests are mostly
 * about what that count has to agree with.
 */
class DrawerSessionTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): void
    {
        Setting::setMany(['store_name' => '88minimart', 'currency_symbol' => '₱', 'tax_rate' => '0']);
        Setting::flushCache();
    }

    private function sell(User $cashier, float $amount, string $method = 'cash'): Order
    {
        $product = Product::factory()->priced(0, $amount)->create(['stock' => 20]);

        return app(OrderService::class)->checkout(
            ['items' => [$product->id => ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => $amount]]],
            ['paid_amount' => $amount, 'payment_method' => $method],
            $cashier,
        );
    }

    public function test_a_cashier_opens_a_drawer_with_a_float(): void
    {
        $this->settings();
        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)
            ->post(route('pos.drawer.open'), ['opening_float' => 500])
            ->assertRedirect(route('drawer.index'));

        $session = CashSession::sole();

        $this->assertEquals(500.00, (float) $session->opening_float);
        $this->assertTrue($session->isOpen());
        $this->assertSame($cashier->id, $session->user_id);
    }

    public function test_expected_cash_is_the_float_plus_cash_sales(): void
    {
        $this->settings();
        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)->post(route('pos.drawer.open'), ['opening_float' => 500]);
        $session = CashSession::sole();

        $this->sell($cashier, 120.00);
        $this->sell($cashier, 80.00);

        // 500 float + 120 + 80
        $this->assertEquals(700.00, $session->expectedCash());
    }

    public function test_gcash_never_enters_the_drawer(): void
    {
        $this->settings();
        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)->post(route('pos.drawer.open'), ['opening_float' => 500]);
        $session = CashSession::sole();

        $this->sell($cashier, 200.00, PaymentMethod::Gcash->value);
        $this->sell($cashier, 50.00, PaymentMethod::Cash->value);

        // Counting GCash would put money in the till that was never physically
        // there, and make every drawer look short by exactly the e-wallet total.
        $this->assertEquals(550.00, $session->expectedCash());
    }

    public function test_a_cash_refund_leaves_the_drawer(): void
    {
        $this->settings();
        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)->post(route('pos.drawer.open'), ['opening_float' => 500]);
        $session = CashSession::sole();

        $order = $this->sell($cashier, 200.00);
        $this->assertEquals(700.00, $session->expectedCash());

        Refund::create([
            'refund_number' => 'REF-000001',
            'order_id' => $order->id,
            'user_id' => $cashier->id,
            'status' => \App\Enums\RefundStatus::Completed,
            'amount' => 50.00,
            'tax_amount' => 0,
            'method' => PaymentMethod::Cash,
            'reason_code' => \App\Enums\RefundReason::ChangedMind,
            'idempotency_key' => 'drawer-test-key',
            'refunded_at' => now(),
        ]);

        $this->assertEquals(650.00, $session->expectedCash());
    }

    public function test_a_gcash_refund_does_not_touch_the_drawer(): void
    {
        $this->settings();
        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)->post(route('pos.drawer.open'), ['opening_float' => 500]);
        $session = CashSession::sole();

        $order = $this->sell($cashier, 200.00, PaymentMethod::Gcash->value);
        $this->assertEquals(500.00, $session->expectedCash());

        Refund::create([
            'refund_number' => 'REF-000002',
            'order_id' => $order->id,
            'user_id' => $cashier->id,
            'status' => \App\Enums\RefundStatus::Completed,
            'amount' => 50.00,
            'tax_amount' => 0,
            'method' => PaymentMethod::Gcash,
            'reason_code' => \App\Enums\RefundReason::ChangedMind,
            'idempotency_key' => 'drawer-test-key-2',
            'refunded_at' => now(),
        ]);

        // The money never reached the drawer, so its return cannot leave it.
        $this->assertEquals(500.00, $session->expectedCash());
    }

    public function test_closing_against_the_count_records_a_balanced_drawer(): void
    {
        $this->settings();
        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)->post(route('pos.drawer.open'), ['opening_float' => 500]);
        $session = CashSession::sole();
        $this->sell($cashier, 200.00);

        $this->actingAs($cashier)
            ->post(route('pos.drawer.close', $session), ['counted_cash' => 700])
            ->assertRedirect(route('drawer.index'));

        $session->refresh();

        $this->assertFalse($session->isOpen());
        $this->assertEquals(0.00, $session->variance());
        $this->assertNotNull($session->closed_at);
    }

    public function test_a_short_drawer_is_recorded_with_its_reason(): void
    {
        $this->settings();
        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)->post(route('pos.drawer.open'), ['opening_float' => 500]);
        $session = CashSession::sole();
        $this->sell($cashier, 200.00);

        $this->actingAs($cashier)->post(route('pos.drawer.close', $session), [
            'counted_cash' => 660,
            'variance_reason' => 'Gave wrong change',
        ]);

        $session->refresh();

        $this->assertEquals(-40.00, $session->variance());
        $this->assertTrue($session->isShort());
        $this->assertSame('Gave wrong change', $session->variance_reason);
    }

    public function test_an_over_drawer_is_not_reported_as_short(): void
    {
        $this->settings();
        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)->post(route('pos.drawer.open'), ['opening_float' => 500]);
        $session = CashSession::sole();

        $this->actingAs($cashier)->post(route('pos.drawer.close', $session), ['counted_cash' => 515]);

        $session->refresh();

        $this->assertEquals(15.00, $session->variance());
        $this->assertTrue($session->isOver());
        $this->assertFalse($session->isShort());
    }

    public function test_a_drawer_cannot_be_opened_twice(): void
    {
        $this->settings();
        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)->post(route('pos.drawer.open'), ['opening_float' => 500]);

        // Two open drawers would count the same float twice and make "what
        // should be in here" ambiguous.
        $this->actingAs($cashier)
            ->post(route('pos.drawer.open'), ['opening_float' => 200])
            ->assertSessionHas('error');

        $this->assertSame(1, CashSession::count());
    }

    public function test_two_cashiers_may_each_hold_a_drawer(): void
    {
        $this->settings();
        $alice = User::factory()->staff()->create();
        $bruno = User::factory()->staff()->create();

        $this->actingAs($alice)->post(route('pos.drawer.open'), ['opening_float' => 500]);
        $this->actingAs($bruno)->post(route('pos.drawer.open'), ['opening_float' => 300]);

        $this->assertSame(2, CashSession::open()->count());
    }

    public function test_a_cashier_cannot_close_someone_elses_drawer(): void
    {
        $this->settings();
        $alice = User::factory()->staff()->create();
        $bruno = User::factory()->staff()->create();

        $this->actingAs($alice)->post(route('pos.drawer.open'), ['opening_float' => 500]);
        $session = CashSession::sole();

        // The count has to come from the person who was holding the money.
        $this->actingAs($bruno)
            ->post(route('pos.drawer.close', $session), ['counted_cash' => 0])
            ->assertSessionHas('error');

        $this->assertTrue($session->refresh()->isOpen());
    }

    public function test_a_cashier_cannot_close_a_drawer_twice(): void
    {
        $this->settings();
        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)->post(route('pos.drawer.open'), ['opening_float' => 500]);
        $session = CashSession::sole();
        $this->actingAs($cashier)->post(route('pos.drawer.close', $session), ['counted_cash' => 500]);

        $this->actingAs($cashier)
            ->post(route('pos.drawer.close', $session), ['counted_cash' => 0])
            ->assertSessionHas('error');
    }

    public function test_admins_read_drawers_but_cannot_open_one(): void
    {
        $this->settings();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('drawer.index'))->assertOk();

        // The owner is never on the till, so they do not open a drawer either.
        $this->actingAs($admin)->post(route('pos.drawer.open'), ['opening_float' => 500])->assertRedirect();
    }

    public function test_closing_the_drawer_dispatches_the_day_summary(): void
    {
        $this->settings();
        Event::fake([DayClosed::class]);

        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)->post(route('pos.drawer.open'), ['opening_float' => 500]);
        $session = CashSession::sole();
        $this->sell($cashier, 200.00);

        $this->actingAs($cashier)->post(route('pos.drawer.close', $session), ['counted_cash' => 700]);

        Event::assertDispatched(DayClosed::class, function (DayClosed $event) {
            return $event->summary['expected_cash'] == 700.0
                && $event->summary['counted_cash'] == 700.0
                && $event->summary['variance'] == 0.0;
        });
    }

    public function test_expected_cash_is_recomputed_after_a_late_refund(): void
    {
        $this->settings();
        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)->post(route('pos.drawer.open'), ['opening_float' => 500]);
        $session = CashSession::sole();

        // Widen the session so it has a real span, rather than the fraction of a
        // second an open-then-immediately-close test produces.
        $session->forceFill(['opened_at' => now()->subHour()])->save();

        $order = $this->sell($cashier, 200.00);

        $this->actingAs($cashier)->post(route('pos.drawer.close', $session), ['counted_cash' => 700]);
        $this->assertEquals(0.00, $session->refresh()->variance());

        // A refund filed after the drawer was counted still belongs to the
        // session it happened in, so the variance has to move. This is why the
        // expected figure is derived and never frozen at close. Dated inside the
        // session window, between the opening and the count.
        Refund::create([
            'refund_number' => 'REF-000003',
            'order_id' => $order->id,
            'user_id' => $cashier->id,
            'status' => \App\Enums\RefundStatus::Completed,
            'amount' => 100.00,
            'tax_amount' => 0,
            'method' => PaymentMethod::Cash,
            'reason_code' => \App\Enums\RefundReason::ChangedMind,
            'idempotency_key' => 'drawer-test-key-3',
            'refunded_at' => now()->subMinutes(30),
        ]);

        $this->assertEquals(600.00, $session->expectedCash());
        $this->assertEquals(100.00, $session->refresh()->variance());
    }

    public function test_sales_outside_the_session_do_not_count(): void
    {
        $this->settings();
        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)->post(route('pos.drawer.open'), ['opening_float' => 500]);

        // A drawer that opened an hour ago and closed five minutes ago.
        CashSession::sole()->forceFill([
            'opened_at' => now()->subHour(),
            'closed_at' => now()->subMinutes(5),
        ])->save();

        // Money taken after the drawer was closed is not part of its
        // arithmetic, or yesterday's shortfall would absorb today's takings.
        $this->travelTo(now()->addMinutes(1));
        $this->sell($cashier, 200.00);

        $this->assertEquals(500.00, CashSession::sole()->expectedCash());
    }

    public function test_the_drawer_screen_renders_for_both_roles(): void
    {
        $this->settings();

        $this->actingAs(User::factory()->staff()->create())->get(route('drawer.index'))->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get(route('drawer.index'))->assertOk();
    }
}
