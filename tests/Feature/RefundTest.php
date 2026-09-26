<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin);

        $this->product = Product::factory()->create(['price' => 10.00, 'stock' => 5]);

        $this->order = Order::factory()->create([
            'user_id' => $this->admin->id,
            'subtotal' => 40.00,
            'total' => 40.00,
        ]);

        $this->order->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'sku' => $this->product->sku,
            'unit_price' => 10.00,
            'quantity' => 4,
            'discount_amount' => 0,
            'line_total' => 40.00,
        ]);
    }

    private function item(): OrderItem
    {
        return $this->order->items()->first();
    }

    /**
     * @param  array<int, array{order_item_id: int, quantity: int, condition: string}>  $items
     */
    private function postRefund(array $items, array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.refunds.store', $this->order), array_merge([
            'items' => $items,
            'method' => Refund::METHOD_CASH,
        ], $overrides));
    }

    public function test_a_partial_return_refunds_only_those_units(): void
    {
        $this->postRefund([[
            'order_item_id' => $this->item()->id,
            'quantity' => 2,
            'condition' => RefundItem::CONDITION_RESTOCKABLE,
        ]])->assertSessionHas('success');

        $refund = Refund::with('items')->sole();

        $this->assertSame('20.00', $refund->total_amount);
        $this->assertSame('cash', $refund->method);
        $this->assertSame($this->admin->id, $refund->user_id);

        $this->order->refresh();
        $this->assertSame('20.00', $this->order->refunded_total);
        $this->assertEquals(20.00, $this->order->net_total);
        $this->assertTrue($this->order->partially_refunded);
    }

    public function test_a_restockable_return_goes_back_into_sellable_stock(): void
    {
        $this->postRefund([[
            'order_item_id' => $this->item()->id,
            'quantity' => 2,
            'condition' => RefundItem::CONDITION_RESTOCKABLE,
        ]]);

        // Stock was 5 after the sale; two units come back.
        $this->assertSame(7, $this->product->fresh()->stock);

        $log = InventoryLog::where('product_id', $this->product->id)->sole();
        $this->assertSame(2, $log->quantity_change);
        $this->assertSame('in', $log->type);
        $this->assertSame(Refund::class, $log->reference_type);
    }

    public function test_a_damaged_return_is_not_resold(): void
    {
        $this->postRefund([[
            'order_item_id' => $this->item()->id,
            'quantity' => 2,
            'condition' => RefundItem::CONDITION_DAMAGED,
        ]])->assertSessionHas('success');

        // The units are on record as scrapped, but they must not reappear as
        // sellable inventory.
        $this->assertSame(5, $this->product->fresh()->stock);
        $this->assertSame(0, InventoryLog::count());

        $refundItem = RefundItem::sole();
        $this->assertSame(RefundItem::CONDITION_DAMAGED, $refundItem->condition);
        $this->assertFalse($refundItem->restocked);
    }

    public function test_more_units_than_were_sold_cannot_be_returned(): void
    {
        $this->postRefund([[
            'order_item_id' => $this->item()->id,
            'quantity' => 5,
            'condition' => RefundItem::CONDITION_RESTOCKABLE,
        ]])->assertSessionHasErrors('items.0.quantity');

        $this->assertSame(0, Refund::count());
        $this->assertSame(5, $this->product->fresh()->stock);
    }

    public function test_units_cannot_be_returned_twice(): void
    {
        $line = [
            'order_item_id' => $this->item()->id,
            'quantity' => 3,
            'condition' => RefundItem::CONDITION_RESTOCKABLE,
        ];

        $this->postRefund([$line])->assertSessionHas('success');

        // Only one unit is left to return.
        $this->postRefund([[
            'order_item_id' => $this->item()->id,
            'quantity' => 2,
            'condition' => RefundItem::CONDITION_RESTOCKABLE,
        ]])->assertSessionHasErrors('items.0.quantity');

        $this->assertSame(1, Refund::count());
        $this->assertSame('30.00', $this->order->fresh()->refunded_total);
    }

    public function test_a_line_can_be_returned_fully_across_several_refunds(): void
    {
        $line = [
            'order_item_id' => $this->item()->id,
            'quantity' => 1,
            'condition' => RefundItem::CONDITION_RESTOCKABLE,
        ];

        $this->postRefund([$line])->assertSessionHas('success');
        $this->postRefund([$line])->assertSessionHas('success');
        $this->postRefund([$line])->assertSessionHas('success');
        $this->postRefund([$line])->assertSessionHas('success');

        $this->order->refresh();
        $this->assertSame('40.00', $this->order->refunded_total);
        $this->assertTrue($this->order->fully_refunded);
        $this->assertEquals(0.00, $this->order->net_total);
    }

    public function test_a_cancelled_order_cannot_be_refunded(): void
    {
        $this->order->update(['status' => Order::STATUS_CANCELLED]);

        $this->postRefund([[
            'order_item_id' => $this->item()->id,
            'quantity' => 1,
            'condition' => RefundItem::CONDITION_RESTOCKABLE,
        ]])->assertSessionHasErrors('items');

        $this->assertSame(0, Refund::count());
    }

    public function test_a_discounted_line_refunds_the_discounted_amount(): void
    {
        $order = Order::factory()->create(['user_id' => $this->admin->id, 'total' => 30.00]);
        $order->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'sku' => $this->product->sku,
            'unit_price' => 10.00,
            'quantity' => 3,
            'discount_amount' => 0,
            'line_total' => 30.00,
        ]);

        // The customer actually paid 30.00 for three units, so a unit refund
        // must not credit the 10.00 list price.
        $this->actingAs($this->admin)->post(route('admin.refunds.store', $order), [
            'items' => [[
                'order_item_id' => $order->items()->first()->id,
                'quantity' => 1,
                'condition' => RefundItem::CONDITION_RESTOCKABLE,
            ]],
            'method' => Refund::METHOD_CASH,
        ])->assertSessionHas('success');

        $this->assertSame('10.00', Refund::sole()->total_amount);
    }

    public function test_a_refund_never_exceeds_the_amount_paid(): void
    {
        // A corrupt order total must not let the till pay out more than the
        // order was worth.
        $this->order->update(['total' => 5.00]);

        $this->postRefund([[
            'order_item_id' => $this->item()->id,
            'quantity' => 4,
            'condition' => RefundItem::CONDITION_RESTOCKABLE,
        ]])->assertSessionHas('success');

        $this->assertSame('5.00', Refund::sole()->total_amount);
        $this->assertSame('5.00', $this->order->fresh()->refunded_total);
    }

    public function test_returning_everything_refunds_exactly_what_was_charged(): void
    {
        // Tax and a cart discount live on the order, not on the lines. They
        // must still come back, or a fully returned sale leaves the customer
        // out of pocket and the order permanently short of "fully refunded".
        $this->order->update([
            'subtotal' => 40.00,
            'discount_type' => 'fixed',
            'discount_amount' => 4.00,
            'tax_rate' => 10.00,
            'tax_amount' => 3.60,
            'total' => 39.60,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.refunds.all', $this->order), ['method' => Refund::METHOD_CASH])
            ->assertSessionHas('success');

        $order = $this->order->fresh();

        $this->assertSame('39.60', Refund::sole()->total_amount);
        $this->assertSame('39.60', $order->refunded_total);
        $this->assertEquals(0.00, $order->net_total);
        $this->assertTrue($order->fully_refunded);
    }

    public function test_tax_is_shared_across_lines_in_proportion_to_their_value(): void
    {
        $cheap = Product::factory()->create(['price' => 10.00]);
        $pricey = Product::factory()->create(['price' => 30.00]);

        $order = Order::factory()->create([
            'user_id' => $this->admin->id,
            'subtotal' => 40.00,
            'tax_rate' => 10.00,
            'tax_amount' => 4.00,
            'total' => 44.00,
        ]);

        $cheapItem = $order->items()->create([
            'product_id' => $cheap->id, 'product_name' => $cheap->name, 'sku' => $cheap->sku,
            'unit_price' => 10.00, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 10.00,
        ]);

        $order->items()->create([
            'product_id' => $pricey->id, 'product_name' => $pricey->name, 'sku' => $pricey->sku,
            'unit_price' => 30.00, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 30.00,
        ]);

        // The 10.00 line carries a quarter of the 4.00 tax.
        $this->actingAs($this->admin)->post(route('admin.refunds.store', $order), [
            'items' => [[
                'order_item_id' => $cheapItem->id,
                'quantity' => 1,
                'condition' => RefundItem::CONDITION_RESTOCKABLE,
            ]],
            'method' => Refund::METHOD_CASH,
        ])->assertSessionHas('success');

        $this->assertSame('11.00', Refund::sole()->total_amount);
    }

    public function test_a_digital_payout_requires_a_reference(): void
    {
        $this->postRefund([[
            'order_item_id' => $this->item()->id,
            'quantity' => 1,
            'condition' => RefundItem::CONDITION_RESTOCKABLE,
        ]], ['method' => Refund::METHOD_DIGITAL])->assertSessionHasErrors('reference_no');

        $this->assertSame(0, Refund::count());
    }

    public function test_a_digital_payout_stores_its_reference(): void
    {
        $this->postRefund([[
            'order_item_id' => $this->item()->id,
            'quantity' => 1,
            'condition' => RefundItem::CONDITION_RESTOCKABLE,
        ]], [
            'method' => Refund::METHOD_DIGITAL,
            'reference_no' => 'RFD-77120',
        ])->assertSessionHas('success');

        $this->assertSame('RFD-77120', Refund::sole()->reference_no);
    }

    public function test_a_refund_with_no_quantity_selected_is_rejected(): void
    {
        $this->postRefund([])->assertSessionHasErrors('items');
    }

    public function test_a_condition_is_required_for_each_line(): void
    {
        $this->postRefund([[
            'order_item_id' => $this->item()->id,
            'quantity' => 1,
        ]])->assertSessionHasErrors('items.0.condition');
    }

    public function test_an_item_from_another_order_cannot_be_returned(): void
    {
        $other = Order::factory()->create(['user_id' => $this->admin->id]);
        $otherItem = $other->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'sku' => $this->product->sku,
            'unit_price' => 10.00,
            'quantity' => 1,
            'discount_amount' => 0,
            'line_total' => 10.00,
        ]);

        $this->postRefund([[
            'order_item_id' => $otherItem->id,
            'quantity' => 1,
            'condition' => RefundItem::CONDITION_RESTOCKABLE,
        ]])->assertSessionHasErrors('items');

        $this->assertSame(0, Refund::count());
    }

    public function test_a_refund_is_recorded_in_the_audit_trail(): void
    {
        $this->postRefund([[
            'order_item_id' => $this->item()->id,
            'quantity' => 1,
            'condition' => RefundItem::CONDITION_RESTOCKABLE,
        ]]);

        $log = AuditLog::where('action', AuditLogger::REFUND_PROCESSED)->sole();

        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(Refund::class, $log->model_type);
        $this->assertNotNull($log->model_id);
    }

    public function test_the_whole_order_can_be_refunded_in_one_action(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.refunds.all', $this->order), [
                'method' => Refund::METHOD_CASH,
                'reason' => 'Customer changed their mind',
            ])
            ->assertSessionHas('success');

        $refund = Refund::with('items')->sole();

        $this->assertSame('40.00', $refund->total_amount);
        $this->assertSame('Customer changed their mind', $refund->reason);
        $this->assertSame(4, $refund->items->sum('quantity'));
        $this->assertSame(9, $this->product->fresh()->stock);
        $this->assertTrue($this->order->fresh()->fully_refunded);
    }

    public function test_staff_cannot_process_refunds(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::Staff);

        $this->actingAs($staff)
            ->post(route('admin.refunds.store', $this->order), [
                'items' => [[
                    'order_item_id' => $this->item()->id,
                    'quantity' => 1,
                    'condition' => RefundItem::CONDITION_RESTOCKABLE,
                ]],
                'method' => Refund::METHOD_CASH,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, Refund::count());
    }

    public function test_guests_cannot_process_refunds(): void
    {
        $this->post(route('admin.refunds.store', $this->order))->assertRedirect(route('login'));
    }

    public function test_the_order_page_shows_the_refund_status(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.refunds.all', $this->order), ['method' => Refund::METHOD_CASH])
            ->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->get(route('orders.show', $this->order->fresh()))
            ->assertOk()
            ->assertSee('This order has been fully refunded')
            ->assertSee('Net kept');
    }

    public function test_a_partially_refunded_order_is_labelled_as_such(): void
    {
        $this->postRefund([[
            'order_item_id' => $this->item()->id,
            'quantity' => 1,
            'condition' => RefundItem::CONDITION_RESTOCKABLE,
        ]]);

        $this->actingAs($this->admin)
            ->get(route('orders.show', $this->order->fresh()))
            ->assertOk()
            ->assertSee('Part of this order has been refunded')
            ->assertSee('Net kept');
    }

    public function test_the_refund_list_and_detail_render(): void
    {
        $this->postRefund([[
            'order_item_id' => $this->item()->id,
            'quantity' => 1,
            'condition' => RefundItem::CONDITION_DAMAGED,
        ]]);

        $this->actingAs($this->admin)->get(route('admin.refunds.index'))
            ->assertOk()
            ->assertSee($this->order->order_number);

        $this->actingAs($this->admin)->get(route('admin.refunds.show', Refund::sole()))
            ->assertOk()
            ->assertSee('Damaged');
    }
}
