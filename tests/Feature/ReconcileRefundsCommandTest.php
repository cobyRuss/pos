<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * pos:reconcile-refunds repairs orders left behind by the refund bug where the
 * order-level tax was never returned. It writes financial corrections, so the
 * tests are as much about what it refuses to do as what it fixes.
 */
class ReconcileRefundsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An order in the pre-fix state: a refund was recorded against the line
     * totals only, so the tax is missing from refunded_total.
     */
    private function strandedOrder(float $refunded = 12.20, float $total = 12.93): Order
    {
        $product = Product::factory()->create(['price' => 12.20]);

        $order = Order::factory()->create([
            'subtotal' => $refunded,
            'tax_rate' => 6.00,
            'tax_amount' => 0.73,
            'total' => $total,
            'refunded_total' => 0,
        ]);

        $item = $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'unit_price' => 12.20,
            'quantity' => 1,
            'discount_amount' => 0,
            'line_total' => 12.20,
        ]);

        $refund = $order->refunds()->create([
            'user_id' => User::factory()->create()->id,
            'total_amount' => $refunded,
            'method' => 'cash',
        ]);

        $refund->items()->create([
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 12.20,
            'amount' => $refunded,
            'condition' => RefundItem::CONDITION_RESTOCKABLE,
            'restocked' => true,
        ]);

        return $order;
    }

    public function test_it_reports_a_clean_database_as_having_nothing_to_do(): void
    {
        $this->artisan('pos:reconcile-refunds')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();
    }

    public function test_it_detects_a_discrepancy_without_changing_anything(): void
    {
        $order = $this->strandedOrder();

        $this->artisan('pos:reconcile-refunds')
            ->expectsOutputToContain($order->order_number)
            ->expectsOutputToContain('No changes made')
            ->assertSuccessful();

        // A dry run must not touch the books.
        $this->assertSame('0.00', $order->fresh()->refunded_total);
    }

    public function test_it_corrects_the_booked_total_to_match_the_refund_lines(): void
    {
        $order = $this->strandedOrder();

        $this->artisan('pos:reconcile-refunds --fix')
            ->expectsOutputToContain('Corrected 1 order')
            ->assertSuccessful();

        $this->assertSame('12.20', $order->fresh()->refunded_total);
    }

    public function test_it_reads_the_refund_lines_exactly(): void
    {
        // Guards the aggregate itself: a silent off-by-a-cent in a financial
        // correction is worse than a crash.
        $order = $this->strandedOrder(refunded: 7.77, total: 8.55);

        $this->artisan('pos:reconcile-refunds --fix')->assertSuccessful();

        $this->assertSame('7.77', $order->fresh()->refunded_total);
    }

    public function test_it_never_books_more_than_the_order_was_worth(): void
    {
        // A refund line that somehow exceeds the order total must not push
        // refunded_total past what the customer paid.
        $order = $this->strandedOrder(refunded: 50.00, total: 12.93);

        $this->artisan('pos:reconcile-refunds --fix')->assertSuccessful();

        $this->assertSame('12.93', $order->fresh()->refunded_total);
    }

    public function test_it_leaves_a_correctly_booked_order_alone(): void
    {
        $order = $this->strandedOrder();
        $order->update(['refunded_total' => 12.20]);

        $this->artisan('pos:reconcile-refunds')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();

        $this->assertSame('12.20', $order->fresh()->refunded_total);
    }

    public function test_it_is_idempotent(): void
    {
        $order = $this->strandedOrder();

        $this->artisan('pos:reconcile-refunds --fix')->assertSuccessful();
        $this->artisan('pos:reconcile-refunds --fix')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();

        $this->assertSame('12.20', $order->fresh()->refunded_total);
    }

    public function test_it_ignores_cancelled_orders(): void
    {
        // A cancelled order was never refunded, so there is nothing to
        // reconcile even if a stray refund row exists.
        $order = $this->strandedOrder();
        $order->update(['status' => Order::STATUS_CANCELLED]);

        $this->artisan('pos:reconcile-refunds')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();
    }

    public function test_it_handles_several_stranded_orders_at_once(): void
    {
        $first = $this->strandedOrder();
        $second = $this->strandedOrder();

        $this->artisan('pos:reconcile-refunds --fix')
            ->expectsOutputToContain('Corrected 2 order(s)')
            ->assertSuccessful();

        $this->assertSame('12.20', $first->fresh()->refunded_total);
        $this->assertSame('12.20', $second->fresh()->refunded_total);
    }

    public function test_a_refund_created_normally_needs_no_reconciliation(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin);

        $product = Product::factory()->create(['price' => 10.00, 'stock' => 10]);
        $order = Order::factory()->create(['user_id' => $admin->id, 'subtotal' => 20.00, 'total' => 20.00]);

        $item = $order->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
            'unit_price' => 10.00, 'quantity' => 2, 'discount_amount' => 0, 'line_total' => 20.00,
        ]);

        $refund = $order->refunds()->create([
            'user_id' => $admin->id, 'total_amount' => 20.00, 'method' => 'cash',
        ]);

        $refund->items()->create([
            'order_item_id' => $item->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'quantity' => 2, 'unit_price' => 10.00, 'amount' => 20.00,
            'condition' => RefundItem::CONDITION_RESTOCKABLE, 'restocked' => true,
        ]);

        $order->update(['refunded_total' => 20.00]);

        $this->artisan('pos:reconcile-refunds')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();
    }
}
