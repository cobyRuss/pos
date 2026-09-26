<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\RefundItem;
use App\Models\User;
use App\Services\Cart;
use App\Services\InventoryService;
use App\Services\LotAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * First Expired, First Out.
 *
 * The rule under test is that short-dated stock leaves first, and — just as
 * important — that a return goes back to the batch it came from rather than
 * whichever batch happens to be current.
 */
class FefoAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected User $staff;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create();
        $this->staff->assignRole(Role::Staff);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin);
    }

    private function allocator(): LotAllocator
    {
        return app(LotAllocator::class);
    }

    /**
     * A tracked product with three batches: soonest, middle, and one with no
     * recorded date.
     */
    private function trackedProduct(): Product
    {
        $product = Product::factory()->create([
            'price' => 10.00,
            'stock' => 0,
            'tracks_expiry' => true,
        ]);

        $this->allocator()->receive($product, 'LATE', 10, today()->addDays(30)->toDateString(), 5.00);
        $this->allocator()->receive($product, 'SOON', 4, today()->addDay()->toDateString(), 4.00);
        $this->allocator()->receive($product, 'MID', 6, today()->addDays(10)->toDateString(), 4.50);
        $this->allocator()->receive($product, 'UNDATED', 8, null, 3.00);

        $product->update(['stock' => 28]);

        return $product->fresh();
    }

    public function test_allocation_takes_the_soonest_expiry_first(): void
    {
        $product = $this->trackedProduct();

        $allocation = $this->allocator()->allocate($product, 2);

        $this->assertCount(1, $allocation);
        $this->assertSame('SOON', $allocation[0]['lot']->code);
        $this->assertSame(2, $allocation[0]['quantity']);
    }

    public function test_allocation_spans_batches_when_one_is_not_enough(): void
    {
        $product = $this->trackedProduct(); // SOON has 4

        $allocation = $this->allocator()->allocate($product, 6);

        $this->assertSame(['SOON', 'MID'], array_column(array_column($allocation, 'lot'), 'code'));
        $this->assertSame([4, 2], array_column($allocation, 'quantity'));
    }

    public function test_an_undated_batch_is_sold_last(): void
    {
        $product = $this->trackedProduct();

        // SOON 4 + MID 6 + LATE 10 = 20, so the twenty-first unit is the first
        // that can possibly come from the undated batch.
        $allocation = $this->allocator()->allocate($product, 21);

        $this->assertSame('UNDATED', $allocation[count($allocation) - 1]['lot']->code);
        $this->assertSame(1, $allocation[count($allocation) - 1]['quantity']);
        $this->assertSame(
            ['SOON', 'MID', 'LATE', 'UNDATED'],
            array_column(array_column($allocation, 'lot'), 'code'),
        );
    }

    public function test_expired_stock_is_never_allocated(): void
    {
        $product = $this->trackedProduct();
        $this->allocator()->receive($product, 'DEAD', 50, today()->subDay()->toDateString(), 1.00);
        $product->update(['stock' => 78]);

        $allocation = $this->allocator()->allocate($product, 1);

        $this->assertNotSame('DEAD', $allocation[0]['lot']->code);
    }

    public function test_expired_stock_is_allocated_when_explicitly_allowed(): void
    {
        $product = $this->trackedProduct();
        $this->allocator()->receive($product, 'DEAD', 5, today()->subDays(3)->toDateString(), 1.00);
        $product->update(['stock' => 33]);

        $allocation = $this->allocator()->allocate($product, 1, allowExpired: true);

        $this->assertSame('DEAD', $allocation[0]['lot']->code);
    }

    public function test_allocating_more_than_is_sellable_is_refused(): void
    {
        $product = $this->trackedProduct();
        $this->allocator()->receive($product, 'DEAD', 99, today()->subDays(9)->toDateString(), 1.00);
        $product->update(['stock' => 127]);

        $this->expectExceptionMessage('Only 28 sellable unit(s)');

        $this->allocator()->allocate($product, 40);
    }

    public function test_a_product_that_does_not_track_expiry_allocates_nothing(): void
    {
        $product = Product::factory()->create(['stock' => 5, 'tracks_expiry' => false]);

        // No batches to choose between: the single stock count is the truth.
        $this->assertSame([], $this->allocator()->allocate($product, 3));
    }

    public function test_sellable_stock_excludes_expired_batches(): void
    {
        $product = $this->trackedProduct();
        $this->allocator()->receive($product, 'DEAD', 7, today()->subDay()->toDateString(), 1.00);
        $product->update(['stock' => 35]);

        // 28 live units, 7 expired.
        $this->assertSame(28, $product->fresh()->sellable_stock);
        $this->assertTrue($product->fresh()->has_expired_stock);
        $this->assertSame(35, $product->fresh()->stock);
    }

    public function test_a_sale_draws_down_the_soonest_batch_and_records_it(): void
    {
        $product = $this->trackedProduct();
        app(Cart::class)->add($product->fresh(), 2);

        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), ['payment_method' => 'cash', 'amount_paid' => 100])
            ->assertSessionHas('success');

        $item = Order::sole()->items->first();
        $allocation = $item->allocations->first();

        $this->assertSame('SOON', $allocation->lot->code);
        $this->assertSame(2, $allocation->quantity);
        // SOON had 4, so 2 remain.
        $this->assertSame(2, $allocation->lot->fresh()->quantity);
        $this->assertSame(26, $product->fresh()->stock);
    }

    public function test_a_sale_spanning_batches_records_each_slice(): void
    {
        $product = $this->trackedProduct();
        app(Cart::class)->add($product->fresh(), 6);

        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), ['payment_method' => 'cash', 'amount_paid' => 100]);

        $item = Order::sole()->items->first();
        $allocations = $item->allocations()->orderBy('id')->get();

        $this->assertCount(2, $allocations);
        $this->assertSame(['SOON', 'MID'], $allocations->pluck('lot.code')->all());
        $this->assertSame([4, 2], $allocations->pluck('quantity')->all());
        $this->assertSame(6, $allocations->sum('quantity'));
    }

    public function test_the_movement_log_records_the_batch(): void
    {
        $product = $this->trackedProduct();
        app(Cart::class)->add($product->fresh(), 2);

        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), ['payment_method' => 'cash', 'amount_paid' => 100]);

        $log = InventoryLog::where('product_id', $product->id)
            ->where('type', 'out')
            ->sole();

        $this->assertNotNull($log->lot_id);
        $this->assertSame('SOON', $log->lot->code);
    }

    public function test_the_order_line_cost_blends_across_batches(): void
    {
        $product = $this->trackedProduct();
        // 4 units of SOON at 4.00 and 2 of MID at 4.50.
        app(Cart::class)->add($product->fresh(), 6);

        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), ['payment_method' => 'cash', 'amount_paid' => 100]);

        $line = Order::sole()->items->first();

        // (4.00*4 + 4.50*2) / 6 = 4.1666.. => 4.17
        $this->assertSame('4.17', $line->unit_cost);
    }

    public function test_a_cashier_cannot_sell_expired_stock(): void
    {
        $product = $this->trackedProduct();

        // Every batch goes past its date, so there is nothing sellable left.
        $product->lots()->update(['expires_at' => today()->subDay()->toDateString()]);
        $product->update(['stock' => 28]);

        // Force it into the cart the way a stale page might.
        app(Cart::class)->add($product->fresh(), 1);

        $this->actingAs($this->staff)
            ->from(route('pos.index'))
            ->post(route('pos.checkout'), [
                'payment_method' => 'cash',
                'amount_paid' => 100,
                // A cashier passing this flag must not be believed.
                'allow_expired' => 1,
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Order::count());
    }

    public function test_an_admin_can_override_and_sell_expired_stock(): void
    {
        $product = $this->trackedProduct();
        $this->allocator()->receive($product, 'DEAD', 5, today()->subDay()->toDateString(), 1.00);
        $product->update(['stock' => 33]);

        app(Cart::class)->add($product->fresh(), 1);

        $this->actingAs($this->admin)
            ->post(route('pos.checkout'), [
                'payment_method' => 'cash',
                'amount_paid' => 100,
                'allow_expired' => 1,
            ])
            ->assertSessionHas('success');

        $this->assertSame(1, Order::count());
        $this->assertSame(
            'DEAD',
            Order::sole()->items->first()->allocations->first()->lot->code,
        );
    }

    public function test_expired_stock_cannot_be_added_to_the_cart(): void
    {
        $product = $this->trackedProduct();
        $this->allocator()->receive($product, 'DEAD', 5, today()->subDay()->toDateString(), 1.00);
        $product->update(['stock' => 33]);

        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, app(Cart::class)->quantityOf($product->id));
    }

    public function test_a_return_goes_back_to_the_batch_it_came_from(): void
    {
        $product = $this->trackedProduct();
        app(Cart::class)->add($product->fresh(), 2);

        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), ['payment_method' => 'cash', 'amount_paid' => 100]);

        $order = Order::sole();
        $item = $item = $order->items->first();

        // SOON was drawn down to 2, MID untouched at 6.
        $this->assertSame(2, $item->allocations->first()->lot->fresh()->quantity);
        $this->assertSame(6, $product->lots()->where('code', 'MID')->first()->quantity);

        $this->actingAs($this->admin)
            ->post(route('admin.refunds.store', $order), [
                'items' => [[
                    'order_item_id' => $item->id,
                    'quantity' => 2,
                    'condition' => RefundItem::CONDITION_RESTOCKABLE,
                ]],
                'method' => 'cash',
            ])
            ->assertSessionHas('success');

        // The whole point: the units return to SOON, not to the newest batch.
        $this->assertSame(4, $product->lots()->where('code', 'SOON')->first()->quantity);
        $this->assertSame(6, $product->lots()->where('code', 'MID')->first()->quantity);
    }

    public function test_a_partial_return_credits_the_original_batch_first(): void
    {
        $product = $this->trackedProduct();
        // 6 units: SOON(4) fully drawn, then 2 from MID. SOON ends at 0,
        // MID at 4.
        app(Cart::class)->add($product->fresh(), 6);

        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), ['payment_method' => 'cash', 'amount_paid' => 100]);

        $order = Order::sole();
        $item = $order->items->first();

        $this->assertSame(0, $product->lots()->where('code', 'SOON')->first()->quantity);
        $this->assertSame(4, $product->lots()->where('code', 'MID')->first()->quantity);

        $this->actingAs($this->admin)
            ->post(route('admin.refunds.store', $order), [
                'items' => [[
                    'order_item_id' => $item->id,
                    'quantity' => 1,
                    'condition' => RefundItem::CONDITION_RESTOCKABLE,
                ]],
                'method' => 'cash',
            ])->assertSessionHas('success');

        // The returned unit goes back to SOON, the earliest batch the line
        // drew from, not to whichever batch happens to hold more.
        $this->assertSame(1, $product->lots()->where('code', 'SOON')->first()->quantity);
        $this->assertSame(4, $product->lots()->where('code', 'MID')->first()->quantity);
    }

    public function test_cancelling_an_order_returns_stock_to_its_batches(): void
    {
        $product = $this->trackedProduct();
        app(Cart::class)->add($product->fresh(), 6);

        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), ['payment_method' => 'cash', 'amount_paid' => 100]);

        $order = Order::sole();

        $this->actingAs($this->admin)
            ->post(route('orders.cancel', $order), ['cancel_reason' => 'Duplicate sale'])
            ->assertSessionHas('success');

        $this->assertSame(4, $product->lots()->where('code', 'SOON')->first()->quantity);
        $this->assertSame(6, $product->lots()->where('code', 'MID')->first()->quantity);
        $this->assertSame(28, $product->fresh()->stock);
    }

    public function test_a_refund_with_a_deleted_batch_still_returns_the_units(): void
    {
        $product = $this->trackedProduct();
        app(Cart::class)->add($product->fresh(), 2);

        $this->actingAs($this->staff)
            ->post(route('pos.checkout'), ['payment_method' => 'cash', 'amount_paid' => 100]);

        $order = Order::sole();
        $item = $order->items->first();

        // The batch is deleted after the sale.
        $item->allocations->first()->lot->delete();

        $this->actingAs($this->admin)
            ->post(route('admin.refunds.store', $order), [
                'items' => [[
                    'order_item_id' => $item->id,
                    'quantity' => 2,
                    'condition' => RefundItem::CONDITION_RESTOCKABLE,
                ]],
                'method' => 'cash',
            ])->assertSessionHas('success');

        // Units are not lost: they land in the holding batch.
        $this->assertSame(28, $product->fresh()->stock);
        $this->assertSame(
            2,
            (int) $product->lots()->where('code', LotAllocator::RECOVERY_CODE)->value('quantity'),
        );
    }

    public function test_an_undated_batch_never_blocks_a_sale(): void
    {
        $product = Product::factory()->create(['price' => 2.00, 'stock' => 0, 'tracks_expiry' => true]);
        $this->allocator()->receive($product, 'NOEXP', 5, null, 1.00);
        $product->update(['stock' => 5]);

        $allocation = $this->allocator()->allocate($product->fresh(), 5);

        $this->assertSame('NOEXP', $allocation[0]['lot']->code);
        $this->assertNull($allocation[0]['lot']->expires_at);
    }

    public function test_a_tracked_product_needs_a_batch_for_every_movement(): void
    {
        $product = $this->trackedProduct();

        $this->expectExceptionMessage('must name the batch');

        app(InventoryService::class)->move(
            product: $product,
            quantityChange: -1,
            type: InventoryService::TYPE_OUT,
        );
    }
}
