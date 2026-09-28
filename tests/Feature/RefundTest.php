<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\PaymentMethod;
use App\Enums\RefundReason;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ring up a simple two-line sale and return the order.
     */
    private function placeOrder(User $cashier, Product $first, Product $second, int $firstQty = 2, int $secondQty = 1): Order
    {
        Setting::setMany(['currency_symbol' => '$', 'tax_rate' => '0']);
        Setting::flushCache();

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), [
            'product_id' => $first->id,
            'quantity' => $firstQty,
        ]);
        $this->actingAs($cashier)->postJson(route('pos.cart.store'), [
            'product_id' => $second->id,
            'quantity' => $secondQty,
        ]);
        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 1000,
        ]);

        return Order::with('items')->latest()->first();
    }

    public function test_a_partial_refund_restocks_only_the_returned_units(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->staff()->create();

        $cola = Product::factory()->priced(1, 2)->create(['stock' => 10]);
        $soap = Product::factory()->priced(3, 5)->create(['stock' => 4]);

        $order = $this->placeOrder($cashier, $cola, $soap, firstQty: 2);

        $this->assertSame(8, $cola->fresh()->stock);
        $this->assertSame(3, $soap->fresh()->stock);

        $colaLine = $order->items->firstWhere('product_id', $cola->id);

        $this->actingAs($admin)
            ->post(route('refunds.store', $order), [
                'reason_code' => RefundReason::Damaged->value,
                'reason' => 'Customer returned one can',
                'method' => PaymentMethod::Cash->value,
                'note' => 'Rested one unit',
                'items' => [$colaLine->id => 1],
            ])
            ->assertRedirect();

        $refund = Refund::sole();

        $this->assertSame('completed', $refund->status->value);
        $this->assertEquals(2.00, (float) $refund->amount);
        $this->assertSame($admin->id, $refund->user_id);

        $this->assertSame(9, $cola->fresh()->stock);
        $this->assertSame(3, $soap->fresh()->stock);

        $order->refresh();
        $this->assertEquals(2.00, (float) $order->refunded_amount);
        $this->assertFalse($order->isFullyRefunded());

        $this->assertDatabaseHas('refund_items', [
            'refund_id' => $refund->id,
            'order_item_id' => $colaLine->id,
            'quantity' => 1,
            'amount' => 2.00,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $cola->id,
            'type' => InventoryMovementType::ReturnRestock->value,
            'quantity' => 1,
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::REFUND_CREATED]);
    }

    public function test_a_full_refund_marks_the_order_as_fully_refunded(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->staff()->create();

        $cola = Product::factory()->priced(1, 2)->create(['stock' => 10]);
        $soap = Product::factory()->priced(3, 5)->create(['stock' => 4]);

        $order = $this->placeOrder($cashier, $cola, $soap);

        $quantities = $order->items->mapWithKeys(fn ($item) => [$item->id => $item->quantity])->all();

        $this->actingAs($admin)
            ->post(route('refunds.store', $order), [
                'reason_code' => RefundReason::ChangedMind->value,
                'method' => PaymentMethod::Gcash->value,
                'items' => $quantities,
            ])
            ->assertRedirect();

        $order->refresh();

        $this->assertTrue($order->isFullyRefunded());
        $this->assertEquals(0.0, $order->netRevenue());
        $this->assertSame(10, $cola->fresh()->stock);
        $this->assertSame(4, $soap->fresh()->stock);
    }

    public function test_refunding_more_than_was_sold_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->staff()->create();

        $cola = Product::factory()->priced(1, 2)->create(['stock' => 10]);
        $soap = Product::factory()->priced(3, 5)->create(['stock' => 4]);

        $order = $this->placeOrder($cashier, $cola, $soap, firstQty: 2);
        $colaLine = $order->items->firstWhere('product_id', $cola->id);

        $this->actingAs($admin)
            ->post(route('refunds.store', $order), [
                'reason_code' => RefundReason::Other->value,
                'method' => PaymentMethod::Cash->value,
                'items' => [$colaLine->id => 5],
            ])
            ->assertSessionHasErrors("items.{$colaLine->id}");

        $this->assertSame(0, Refund::count());
        $this->assertSame(8, $cola->fresh()->stock);
    }

    public function test_a_refund_needs_at_least_one_item(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->staff()->create();

        $cola = Product::factory()->priced(1, 2)->create(['stock' => 10]);
        $soap = Product::factory()->priced(3, 5)->create(['stock' => 4]);

        $order = $this->placeOrder($cashier, $cola, $soap);

        $this->actingAs($admin)
            ->post(route('refunds.store', $order), [
                'reason_code' => RefundReason::Other->value,
                'method' => PaymentMethod::Cash->value,
                'items' => [],
            ])
            ->assertSessionHasErrors('items');
    }

    public function test_a_cashier_can_refund_their_own_sale_without_an_admin_present(): void
    {
        // The whole reason this feature was rebuilt: the administrator is never on
        // the till, so a cashier refusing refunds means a customer standing at the
        // counter with no way to be served.
        $cashier = User::factory()->staff()->create();
        $cola = Product::factory()->priced(1, 2)->create(['stock' => 10]);
        $soap = Product::factory()->priced(3, 5)->create(['stock' => 4]);

        $order = $this->placeOrder($cashier, $cola, $soap);
        $line = $order->items->firstWhere('product_id', $cola->id);

        $this->actingAs($cashier)->get(route('refunds.create', $order))->assertOk();

        $this->actingAs($cashier)
            ->post(route('refunds.store', $order), [
                'reason_code' => RefundReason::WrongItem->value,
                'method' => PaymentMethod::Cash->value,
                'items' => [$line->id => 1],
            ])
            ->assertRedirect();

        $refund = Refund::sole();

        // Bound to the cashier's own account and timestamp, which is what the
        // CCTV footage and the daily report get checked against.
        $this->assertSame($cashier->id, $refund->user_id);
        $this->assertSame('wrong_item', $refund->reason_code->value);
        $this->assertNotNull($refund->refunded_at);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::REFUND_CREATED]);
    }

    public function test_a_cashier_cannot_see_another_cashiers_refund(): void
    {
        $alice = User::factory()->staff()->create(['name' => 'Alice']);
        $bruno = User::factory()->staff()->create(['name' => 'Bruno']);
        $cola = Product::factory()->priced(1, 2)->create(['stock' => 20]);
        $soap = Product::factory()->priced(3, 5)->create(['stock' => 20]);

        $order = $this->placeOrder($alice, $cola, $soap);
        $line = $order->items->firstWhere('product_id', $cola->id);

        $this->actingAs($alice)->post(route('refunds.store', $order), [
            'reason_code' => RefundReason::Damaged->value,
            'method' => PaymentMethod::Cash->value,
            'items' => [$line->id => 1],
        ]);

        $refund = Refund::sole();

        $this->actingAs($alice)->get(route('refunds.show', $refund))->assertOk();
        $this->actingAs($bruno)->get(route('refunds.show', $refund))->assertForbidden();
    }

    public function test_a_fully_refunded_order_cannot_be_refunded_again(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->staff()->create();

        $cola = Product::factory()->priced(1, 2)->create(['stock' => 10]);
        $soap = Product::factory()->priced(3, 5)->create(['stock' => 4]);

        $order = $this->placeOrder($cashier, $cola, $soap);
        $line = $order->items->firstWhere('product_id', $cola->id);

        $this->actingAs($admin)->post(route('refunds.store', $order), [
            'reason_code' => RefundReason::Damaged->value,
            'method' => PaymentMethod::Cash->value,
            'items' => [$line->id => $line->quantity],
        ]);

        $this->actingAs($admin)->post(route('refunds.store', $order), [
            'reason_code' => RefundReason::Damaged->value,
            'method' => PaymentMethod::Cash->value,
            'items' => [$line->id => 1],
        ])->assertSessionHasErrors();

        $this->assertSame(1, Refund::count());
    }

    public function test_refund_pages_render_for_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->staff()->create();

        $cola = Product::factory()->priced(1, 2)->create(['stock' => 10]);
        $soap = Product::factory()->priced(3, 5)->create(['stock' => 4]);

        $order = $this->placeOrder($cashier, $cola, $soap);
        $line = $order->items->firstWhere('product_id', $cola->id);

        $this->actingAs($admin)->get(route('refunds.create', $order))->assertOk();

        $this->actingAs($admin)->post(route('refunds.store', $order), [
            'reason_code' => RefundReason::Damaged->value,
            'method' => PaymentMethod::Cash->value,
            'items' => [$line->id => 1],
        ]);

        $refund = Refund::sole();

        $this->actingAs($admin)
            ->get(route('admin.refunds.index'))
            ->assertOk()
            ->assertSee($refund->refund_number)
            ->assertSee(RefundReason::Damaged->label());

        $this->actingAs($admin)
            ->get(route('refunds.show', $refund))
            ->assertOk()
            ->assertSee($refund->refund_number)
            ->assertSee($order->order_number);
    }

    public function test_cancelling_an_order_puts_the_units_back_and_blocks_a_second_cancel(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->staff()->create();

        $cola = Product::factory()->priced(1, 2)->create(['stock' => 10]);
        $soap = Product::factory()->priced(3, 5)->create(['stock' => 4]);

        $order = $this->placeOrder($cashier, $cola, $soap, firstQty: 3);
        $this->assertSame(7, $cola->fresh()->stock);

        $this->actingAs($admin)
            ->post(route('orders.cancel', $order), ['reason' => 'Customer changed mind'])
            ->assertRedirect();

        $order->refresh();

        $this->assertTrue($order->isCancelled());
        $this->assertSame(10, $cola->fresh()->stock);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $cola->id,
            'type' => InventoryMovementType::SaleCancellation->value,
            'quantity' => 3,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::ORDER_CANCELLED]);

        $this->actingAs($admin)
            ->post(route('orders.cancel', $order), ['reason' => 'Again'])
            ->assertSessionHas('error');

        $this->assertSame(10, $cola->fresh()->stock);
    }

    public function test_a_refunded_order_cannot_be_cancelled(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->staff()->create();

        $cola = Product::factory()->priced(1, 2)->create(['stock' => 10]);
        $soap = Product::factory()->priced(3, 5)->create(['stock' => 4]);

        $order = $this->placeOrder($cashier, $cola, $soap);
        $line = $order->items->firstWhere('product_id', $cola->id);

        $this->actingAs($admin)->post(route('refunds.store', $order), [
            'reason_code' => RefundReason::Damaged->value,
            'method' => PaymentMethod::Cash->value,
            'items' => [$line->id => $line->quantity],
        ]);

        $this->actingAs($admin)
            ->post(route('orders.cancel', $order), ['reason' => 'Nope'])
            ->assertSessionHas('error');

        $this->assertFalse($order->fresh()->isCancelled());
    }

    public function test_staff_only_see_their_own_orders(): void
    {
        $admin = User::factory()->admin()->create();
        $alice = User::factory()->staff()->create(['name' => 'Alice']);
        $bruno = User::factory()->staff()->create(['name' => 'Bruno']);

        $product = Product::factory()->priced(1, 2)->create(['stock' => 50]);

        $this->placeOrder($alice, $product, $product);
        $this->placeOrder($bruno, $product, $product);

        $this->assertSame(2, Order::count());

        $this->actingAs($alice)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('Sales (1)')
            ->assertDontSee('Mine only')
            ->assertDontSee('Sales (2)');

        $this->actingAs($admin)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('Sales (2)');

        $brunoOrder = Order::where('user_id', $bruno->id)->sole();

        $this->actingAs($alice)
            ->get(route('orders.show', $brunoOrder))
            ->assertForbidden();
    }

    public function test_order_history_can_be_filtered_by_status_and_method(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 50]);

        $this->placeOrder($cashier, $product, $product);

        $order = Order::sole();
        $order->forceFill(['payment_method' => PaymentMethod::Gcash])->save();

        $this->actingAs($admin)
            ->get(route('orders.index', ['status' => 'completed']))
            ->assertOk()
            ->assertSee($order->order_number);

        $this->actingAs($admin)
            ->get(route('orders.index', ['payment_method' => PaymentMethod::Cash->value]))
            ->assertOk()
            ->assertDontSee($order->order_number);

        $this->actingAs($admin)
            ->get(route('orders.index', ['payment_method' => PaymentMethod::Gcash->value]))
            ->assertOk()
            ->assertSee($order->order_number);
    }
}
