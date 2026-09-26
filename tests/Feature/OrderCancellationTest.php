<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin);

        $this->staff = User::factory()->create();
        $this->staff->assignRole(Role::Staff);
    }

    /**
     * A completed sale of two units, as checkout would have left it.
     */
    private function completedSale(Product $product, int $quantity = 2): Order
    {
        $order = Order::factory()->create([
            'user_id' => $this->staff->id,
            'total' => 10.00 * $quantity,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'unit_price' => 10.00,
            'unit_cost' => 6.00,
            'quantity' => $quantity,
            'discount_amount' => 0,
            'line_total' => 10.00 * $quantity,
        ]);

        return $order;
    }

    public function test_cancelling_restores_stock_and_marks_the_order_void(): void
    {
        $product = Product::factory()->create(['stock' => 3]);
        $order = $this->completedSale($product, 2);

        $this->actingAs($this->admin)
            ->post(route('orders.cancel', $order), ['cancel_reason' => 'Customer changed their mind'])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('success');

        $order->refresh();

        $this->assertTrue($order->is_cancelled);
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertSame('Customer changed their mind', $order->cancel_reason);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame($this->admin->id, $order->cancelled_by);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_cancelling_writes_an_inventory_movement_referencing_the_order(): void
    {
        $product = Product::factory()->create(['stock' => 3]);
        $order = $this->completedSale($product, 2);

        $this->actingAs($this->admin)
            ->post(route('orders.cancel', $order), ['cancel_reason' => 'Wrong item scanned']);

        $log = InventoryLog::where('product_id', $product->id)->sole();

        $this->assertSame(2, $log->quantity_change);
        $this->assertSame('in', $log->type);
        $this->assertSame(Order::class, $log->reference_type);
        $this->assertSame($order->id, $log->reference_id);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_an_order_cannot_be_cancelled_twice(): void
    {
        $product = Product::factory()->create(['stock' => 3]);
        $order = $this->completedSale($product, 1);

        $this->actingAs($this->admin)
            ->post(route('orders.cancel', $order), ['cancel_reason' => 'First reason']);

        // The second attempt must not restock again.
        $this->actingAs($this->admin)
            ->post(route('orders.cancel', $order), ['cancel_reason' => 'Second attempt'])
            ->assertSessionHas('error');

        $this->assertSame(4, $product->fresh()->stock);
        $this->assertSame(1, InventoryLog::count());
        $this->assertSame('First reason', $order->fresh()->cancel_reason);
    }

    public function test_staff_cannot_cancel_orders(): void
    {
        $product = Product::factory()->create(['stock' => 3]);
        $order = $this->completedSale($product, 1);

        // orders.cancel is not granted to staff. Browser requests are bounced
        // with a flash rather than shown a raw 403.
        $this->actingAs($this->staff)
            ->post(route('orders.cancel', $order), ['cancel_reason' => 'Not allowed'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertFalse($order->fresh()->is_cancelled);
        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_a_cancellation_reason_is_required(): void
    {
        $product = Product::factory()->create(['stock' => 3]);
        $order = $this->completedSale($product, 1);

        $this->actingAs($this->admin)
            ->post(route('orders.cancel', $order), [])
            ->assertSessionHasErrors('cancel_reason');

        $this->assertFalse($order->fresh()->is_cancelled);
    }

    public function test_cancelling_a_refunded_order_is_refused(): void
    {
        $product = Product::factory()->create(['stock' => 3]);
        $order = $this->completedSale($product, 1);
        $order->update(['refunded_total' => 10.00]);

        $this->actingAs($this->admin)
            ->post(route('orders.cancel', $order), ['cancel_reason' => 'Attempt'])
            ->assertSessionHas('error');

        $this->assertFalse($order->fresh()->is_cancelled);
        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_a_deleted_product_does_not_block_cancellation(): void
    {
        $product = Product::factory()->create(['stock' => 3]);
        $order = $this->completedSale($product, 1);

        // The line survives with a null product_id; the order must still be
        // cancellable rather than throwing a foreign key error.
        $product->delete();

        $this->actingAs($this->admin)
            ->post(route('orders.cancel', $order), ['cancel_reason' => 'Product withdrawn'])
            ->assertSessionHas('success');

        $this->assertTrue($order->fresh()->is_cancelled);
    }

    public function test_the_service_refuses_an_empty_reason(): void
    {
        $product = Product::factory()->create(['stock' => 3]);
        $order = $this->completedSale($product, 1);
        $order->update(['status' => Order::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $this->expectException(\RuntimeException::class);

        app(OrderCancellationService::class)->cancel($order, $this->admin, 'again');
    }
}
