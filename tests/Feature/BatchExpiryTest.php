<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\PaymentMethod;
use App\Enums\RefundReason;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Setting;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Per-delivery expiry dates: recording them, and what the till is allowed to do
 * with them.
 */
class BatchExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function seedSettings(): void
    {
        Setting::setMany(['store_name' => 'Test Store', 'currency_symbol' => '$', 'tax_rate' => '10']);
        Setting::flushCache();
    }

    /**
     * products.stock is a cached sum of the lots, so every test here re-checks
     * it. If the two ever disagree the whole model is lying.
     */
    private function assertStockMatchesLots(Product $product): void
    {
        $product->refresh();

        $this->assertSame(
            (int) $product->batches()->sum('quantity'),
            (int) $product->stock,
            'products.stock must equal the sum of its delivery lots.'
        );
    }

    private function buy(User $cashier, Product $product, int $quantity): Order
    {
        $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => $quantity])
            ->assertOk();

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 1000.00,
        ])->assertRedirect();

        return Order::latest('id')->firstOrFail();
    }

    // ---------------------------------------------------------------- FEFO --

    public function test_a_sale_draws_from_the_soonest_expiring_lot_first(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 0]);

        $late = $product->batches()->create(['batch_no' => 'LATE', 'expiry_date' => now()->addMonths(9)->toDateString(), 'quantity' => 20]);
        $soon = $product->batches()->create(['batch_no' => 'SOON', 'expiry_date' => now()->addDays(10)->toDateString(), 'quantity' => 5]);
        $undated = $product->batches()->create(['batch_no' => 'GENERAL', 'quantity' => 30]);
        $product->forceFill(['stock' => 55])->save();

        $this->buy($cashier, $product, 8);

        // Strict first-expiry-first-out: the 10-day lot drains, then the 9-month
        // lot, and only an undated lot would come after a dated one. Nothing
        // reaches the general bucket while a dated lot still holds stock.
        $this->assertSame(0, $soon->fresh()->quantity);
        $this->assertSame(17, $late->fresh()->quantity);
        $this->assertSame(30, $undated->fresh()->quantity);
        $this->assertSame(47, $product->fresh()->stock);
        $this->assertStockMatchesLots($product);
    }

    public function test_an_undated_lot_is_only_used_once_every_dated_lot_is_gone(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 0]);

        $dated = $product->batches()->create(['batch_no' => 'DATED', 'expiry_date' => now()->addDays(10)->toDateString(), 'quantity' => 2]);
        $undated = $product->batches()->create(['batch_no' => 'GENERAL', 'quantity' => 30]);
        $product->forceFill(['stock' => 32])->save();

        $this->buy($cashier, $product, 5);

        $this->assertSame(0, $dated->fresh()->quantity);
        $this->assertSame(27, $undated->fresh()->quantity);
    }

    public function test_a_sale_spanning_two_lots_writes_one_movement_per_lot_and_chains_them(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 0]);

        $soon = $product->batches()->create(['batch_no' => 'SOON', 'expiry_date' => now()->addDays(5)->toDateString(), 'quantity' => 3]);
        $later = $product->batches()->create(['batch_no' => 'LATER', 'expiry_date' => now()->addMonths(6)->toDateString(), 'quantity' => 10]);
        $product->forceFill(['stock' => 13])->save();

        $this->buy($cashier, $product, 6);

        $movements = InventoryMovement::where('product_id', $product->id)
            ->where('type', InventoryMovementType::Sale->value)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $movements, 'One movement row per lot touched.');

        // Rows chain: the first closes at 10, the second picks up from there.
        $this->assertSame($soon->id, $movements[0]->batch_id);
        $this->assertSame(-3, $movements[0]->quantity);
        $this->assertSame(13, $movements[0]->before_stock);
        $this->assertSame(10, $movements[0]->after_stock);

        $this->assertSame($later->id, $movements[1]->batch_id);
        $this->assertSame(-3, $movements[1]->quantity);
        $this->assertSame(10, $movements[1]->before_stock);
        $this->assertSame(7, $movements[1]->after_stock);

        // Each row snapshots the date it moved under.
        $this->assertSame(
            now()->addDays(5)->toDateString(),
            $movements[0]->expiry_date->toDateString()
        );
    }

    public function test_expired_lots_are_never_sold(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 0]);

        $expired = $product->batches()->create(['batch_no' => 'GONE', 'expiry_date' => now()->subDay()->toDateString(), 'quantity' => 8]);
        $good = $product->batches()->create(['batch_no' => 'OK', 'expiry_date' => now()->addMonths(3)->toDateString(), 'quantity' => 4]);
        $product->forceFill(['stock' => 12])->save();

        $this->buy($cashier, $product, 4);

        $this->assertSame(8, $expired->fresh()->quantity, 'The expired lot must be left alone.');
        $this->assertSame(0, $good->fresh()->quantity);
        $this->assertSame(8, $product->fresh()->stock, 'The 8 expired units are still counted as stock.');
        $this->assertSame(0, $product->fresh()->sellableStock());
    }

    public function test_a_lot_is_sellable_through_its_expiry_date(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 0]);

        // Expiring today: still good until midnight, so it can be sold.
        $today = $product->batches()->create(['expiry_date' => now()->toDateString(), 'quantity' => 5]);
        $product->forceFill(['stock' => 5])->save();

        $this->assertFalse($today->isExpired());

        $this->buy($cashier, $product, 2);

        $this->assertSame(3, $today->fresh()->quantity);
    }

    public function test_checkout_is_refused_when_only_expired_stock_is_left(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 0]);

        $product->batches()->create(['expiry_date' => now()->subWeek()->toDateString(), 'quantity' => 6]);
        $product->forceFill(['stock' => 6])->save();

        $this->assertTrue($product->fresh()->isFullyExpired());

        $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_partial_expired_stock_blocks_only_what_cannot_be_sold(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 0]);

        $product->batches()->create(['expiry_date' => now()->subWeek()->toDateString(), 'quantity' => 6]);
        $product->batches()->create(['expiry_date' => now()->addMonths(2)->toDateString(), 'quantity' => 3]);
        $product->forceFill(['stock' => 9])->save();

        $this->assertSame(3, $product->fresh()->sellableStock());
        $this->assertSame(6, $product->fresh()->expiredQuantity());

        $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 4])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Only 3 of 9 unit(s) of "'.$product->name.'" can be sold - the rest are past their expiry date.']);
    }

    // ------------------------------------------------------- restock paths --

    public function test_cancelling_an_order_returns_units_to_the_lot_they_came_from(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 0]);

        $soon = $product->batches()->create(['batch_no' => 'SOON', 'expiry_date' => now()->addDays(4)->toDateString(), 'quantity' => 2]);
        $later = $product->batches()->create(['batch_no' => 'LATER', 'expiry_date' => now()->addMonths(5)->toDateString(), 'quantity' => 20]);
        $product->forceFill(['stock' => 22])->save();

        $order = $this->buy($cashier, $product, 5);

        $this->assertSame(0, $soon->fresh()->quantity);
        $this->assertSame(17, $later->fresh()->quantity);

        $this->actingAs($admin)
            ->post(route('orders.cancel', $order), ['reason' => 'Customer changed mind'])
            ->assertRedirect();

        // Everything goes back where it was, dates intact.
        $this->assertSame(2, $soon->fresh()->quantity);
        $this->assertSame(20, $later->fresh()->quantity);
        $this->assertSame(22, $product->fresh()->stock);
        $this->assertStockMatchesLots($product);
    }

    public function test_a_refund_returns_units_to_the_lot_they_came_from(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 0]);

        $lot = $product->batches()->create(['batch_no' => 'SOON', 'expiry_date' => now()->addDays(6)->toDateString(), 'quantity' => 10]);
        $product->forceFill(['stock' => 10])->save();

        $order = $this->buy($cashier, $product, 4);
        $this->assertSame(6, $lot->fresh()->quantity);

        $this->actingAs($admin)
            ->post(route('refunds.store', $order), [
                'reason_code' => RefundReason::Damaged->value,
                'method' => PaymentMethod::Cash->value,
                'items' => [$order->items()->first()->id => 2],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(8, $lot->fresh()->quantity, 'Returned units keep the lot they were sold from.');
        // 10 held, 4 sold, 2 returned.
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertStockMatchesLots($product);
    }

    public function test_a_partial_refund_returns_units_to_the_right_lots_of_a_multi_lot_sale(): void
    {
        $this->seedSettings();

        $cashier = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 0]);

        $soon = $product->batches()->create(['batch_no' => 'SOON', 'expiry_date' => now()->addDays(3)->toDateString(), 'quantity' => 2]);
        $later = $product->batches()->create(['batch_no' => 'LATER', 'expiry_date' => now()->addMonths(4)->toDateString(), 'quantity' => 20]);
        $product->forceFill(['stock' => 22])->save();

        // 6 sold: 2 from SOON, 4 from LATER.
        $order = $this->buy($cashier, $product, 6);
        $item = $order->items()->first();

        $this->assertDatabaseHas('order_item_batches', ['order_item_id' => $item->id, 'batch_id' => $soon->id, 'quantity' => 2]);
        $this->assertDatabaseHas('order_item_batches', ['order_item_id' => $item->id, 'batch_id' => $later->id, 'quantity' => 4]);

        // Returning 3 must unwind the consumption order, not dump them all on
        // whichever lot happened to be biggest.
        $this->actingAs($admin)
            ->post(route('refunds.store', $order), [
                'reason_code' => RefundReason::ChangedMind->value,
                'method' => PaymentMethod::Gcash->value,
                'items' => [$item->id => 3],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // SOON held 0 of 2 after the sale, LATER held 16 of 20. Returning 3
        // unwinds the consumption order: 2 go back to SOON, only 1 to LATER.
        $this->assertSame(2, $soon->fresh()->quantity, 'The 2 from the 3-day lot came back first.');
        $this->assertSame(17, $later->fresh()->quantity, 'Only the third unit reached the later lot.');
        $this->assertSame(19, $product->fresh()->stock);
        $this->assertStockMatchesLots($product);

        // What is still out is tracked, not lost.
        $this->assertDatabaseHas('order_item_batches', ['order_item_id' => $item->id, 'batch_id' => $soon->id, 'quantity' => 0]);
        $this->assertDatabaseHas('order_item_batches', ['order_item_id' => $item->id, 'batch_id' => $later->id, 'quantity' => 3]);
    }

    // ------------------------------------------------------------ recording --

    public function test_creating_a_product_stores_the_expiry_on_its_opening_lot(): void
    {
        $admin = User::factory()->admin()->create();
        $expiry = now()->addMonths(4)->toDateString();

        $this->actingAs($admin)->post(route('admin.products.store'), [
            'name' => 'Greek Yoghurt 500g',
            'cost_price' => 1.60,
            'selling_price' => 3.25,
            'stock' => 26,
            'low_stock_threshold' => 8,
            'unit' => 'pcs',
            'is_active' => 1,
            'expiry_date' => $expiry,
        ])->assertRedirect(route('products.index'));

        $product = Product::where('name', 'Greek Yoghurt 500g')->sole();

        $this->assertDatabaseCount('product_batches', 1);
        $this->assertSame($expiry, $product->batches()->sole()->expiry_date->toDateString());
        $this->assertSame(26, $product->stock);
        $this->assertStockMatchesLots($product);
    }

    public function test_editing_a_product_leaves_its_lot_dates_alone(): void
    {
        $admin = User::factory()->admin()->create();
        $expiry = now()->addMonths(2)->toDateString();
        $product = Product::factory()->create(['stock' => 0, 'name' => 'Butter']);
        $product->batches()->create(['batch_no' => 'B1', 'expiry_date' => $expiry, 'quantity' => 10]);
        $product->forceFill(['stock' => 10])->save();

        $this->actingAs($admin)->put(route('admin.products.update', $product), [
            'name' => 'Salted Butter 250g',
            'cost_price' => 2.10,
            'selling_price' => 4.25,
            'stock' => 10,
            'low_stock_threshold' => 5,
            'unit' => 'pcs',
            'is_active' => 1,
            // A stray date must not rewrite the lot: dates are edited on the
            // lots screen, where there is only one lot in view.
            'expiry_date' => now()->addYears(5)->toDateString(),
        ])->assertRedirect(route('products.index'));

        $this->assertSame('Salted Butter 250g', $product->fresh()->name);
        $this->assertSame($expiry, $product->batches()->sole()->expiry_date->toDateString());
        $this->assertStockMatchesLots($product);
    }

    public function test_an_admin_can_record_a_new_delivery_lot(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 0]);
        $expiry = now()->addDays(45)->toDateString();

        $this->actingAs($admin)
            ->post(route('admin.batches.store', $product), [
                'batch_no' => 'LOT-7781',
                'expiry_date' => $expiry,
                'quantity' => 24,
                'notes' => 'Chiller 2',
            ])
            ->assertRedirect(route('admin.batches.index', $product))
            ->assertSessionHas('success');

        $batch = $product->batches()->sole();
        $this->assertSame('LOT-7781', $batch->batch_no);
        $this->assertSame(24, $batch->quantity);
        $this->assertSame('Chiller 2', $batch->notes);

        $this->assertSame(24, $product->fresh()->stock, 'The lot must feed the product total.');
        $this->assertStockMatchesLots($product);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::STOCK_ADJUSTED]);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'type' => InventoryMovementType::StockIn->value,
            'quantity' => 24,
        ]);
    }

    public function test_a_past_expiry_date_is_refused_on_a_new_lot(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 0]);

        $this->actingAs($admin)
            ->post(route('admin.batches.store', $product), [
                'batch_no' => 'OLD',
                'expiry_date' => now()->subDay()->toDateString(),
                'quantity' => 5,
            ])
            ->assertSessionHasErrors('expiry_date');

        $this->assertDatabaseCount('product_batches', 0);
    }

    public function test_a_lot_with_units_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 5]);
        $batch = $product->batches()->sole();

        $this->actingAs($admin)
            ->delete(route('admin.batches.destroy', $batch))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('product_batches', ['id' => $batch->id]);

        // Once the units are gone the lot can go.
        $batch->forceFill(['quantity' => 0])->save();

        $this->actingAs($admin)
            ->delete(route('admin.batches.destroy', $batch))
            ->assertRedirect(route('admin.batches.index', $product));

        $this->assertDatabaseMissing('product_batches', ['id' => $batch->id]);
    }

    public function test_deleting_a_lot_keeps_the_stock_movement_history(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 0]);
        $expiry = now()->addWeeks(2)->toDateString();

        $this->actingAs($admin)
            ->post(route('admin.batches.store', $product), [
                'batch_no' => 'TEMP',
                'expiry_date' => $expiry,
                'quantity' => 9,
            ])
            ->assertSessionHasNoErrors();

        $batch = $product->batches()->sole();
        $batch->forceFill(['quantity' => 0])->save();

        $this->actingAs($admin)->delete(route('admin.batches.destroy', $batch));

        $this->assertDatabaseMissing('product_batches', ['id' => $batch->id]);

        // nullOnDelete: the log entry survives, with its expiry snapshot intact,
        // and simply loses the link to the lot.
        $movement = InventoryMovement::where('product_id', $product->id)->sole();
        $this->assertNull($movement->batch_id);
        $this->assertSame($expiry, $movement->expiry_date->toDateString());
    }

    public function test_expired_stock_can_be_written_off_against_a_named_lot(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 0]);

        $expired = $product->batches()->create(['batch_no' => 'GONE', 'expiry_date' => now()->subDays(3)->toDateString(), 'quantity' => 6]);
        $product->forceFill(['stock' => 6])->save();

        // A stock-out with no lot would be refused, because FEFO will not touch
        // an expired lot. Naming it explicitly is the deliberate write-off.
        $this->actingAs($admin)
            ->post(route('admin.inventory.store', $product), [
                'type' => InventoryMovementType::StockOut->value,
                'quantity' => 6,
                'reason' => 'Expired - written off',
                'batch_id' => $expired->id,
            ])
            ->assertRedirect(route('inventory.index'));

        $this->assertSame(0, $expired->fresh()->quantity);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertStockMatchesLots($product);
    }

    // ------------------------------------------------------------- filters --

    public function test_the_inventory_list_can_be_filtered_by_expiry(): void
    {
        $admin = User::factory()->admin()->create();

        $expiring = Product::factory()->create(['name' => 'Bananas 1kg', 'stock' => 0]);
        $expiring->batches()->create(['expiry_date' => now()->addDays(5)->toDateString(), 'quantity' => 10]);
        $expiring->forceFill(['stock' => 10])->save();

        $expired = Product::factory()->create(['name' => 'Baby Spinach 200g', 'stock' => 0]);
        $expired->batches()->create(['expiry_date' => now()->subDays(2)->toDateString(), 'quantity' => 4]);
        $expired->forceFill(['stock' => 4])->save();

        Product::factory()->create(['name' => 'Dish Soap 500ml', 'stock' => 20]);

        $this->actingAs($admin)
            ->get(route('inventory.index', ['status' => 'expiring']))
            ->assertOk()
            ->assertSee('Bananas 1kg')
            ->assertDontSee('Baby Spinach 200g');

        $this->actingAs($admin)
            ->get(route('inventory.index', ['status' => 'expired']))
            ->assertOk()
            ->assertSee('Baby Spinach 200g')
            ->assertDontSee('Bananas 1kg');

        $this->actingAs($admin)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('Expires in 5 days')
            ->assertSee('Expired 2 days ago');
    }

    public function test_the_inventory_report_includes_expiry_columns_and_filters(): void
    {
        $admin = User::factory()->admin()->create();

        $soon = Product::factory()->create(['name' => 'Tomatoes 1kg', 'stock' => 0]);
        $soon->batches()->create(['expiry_date' => now()->addDays(9)->toDateString(), 'quantity' => 7]);
        $soon->forceFill(['stock' => 7])->save();

        $this->actingAs($admin)
            ->get(route('admin.reports.inventory', ['status' => 'expiring']))
            ->assertOk()
            ->assertSee('Tomatoes 1kg')
            ->assertSee('Next Expiry');

        $csv = $this->actingAs($admin)
            ->get(route('admin.reports.inventory', ['status' => 'expiring', 'export' => 1]))
            ->assertOk();

        $body = $csv->streamedContent();

        $this->assertStringContainsString('Next Expiry', $body);
        $this->assertStringContainsString('Expiring soon', $body);
        $this->assertStringContainsString($soon->name, $body);
    }

    public function test_the_dashboard_surfaces_upcoming_expiries(): void
    {
        $admin = User::factory()->admin()->create();

        $product = Product::factory()->create(['name' => 'Red Apples 1kg', 'stock' => 0]);
        $product->batches()->create(['expiry_date' => now()->addDays(3)->toDateString(), 'quantity' => 9]);
        $product->forceFill(['stock' => 9])->save();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Expiry Watch')
            ->assertSee('Red Apples 1kg');
    }

    // ---------------------------------------------------------- role guards --

    public function test_staff_cannot_manage_lots(): void
    {
        $staff = User::factory()->staff()->create();
        $product = Product::factory()->create(['stock' => 5]);
        $batch = $product->batches()->sole();

        $this->actingAs($staff)
            ->get(route('admin.batches.index', $product))
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('error');

        $this->actingAs($staff)
            ->post(route('admin.batches.store', $product), [
                'expiry_date' => now()->addMonth()->toDateString(),
                'quantity' => 5,
            ])
            ->assertRedirect(route('pos.index'));

        $this->assertDatabaseCount('product_batches', 1);

        $this->actingAs($staff)->delete(route('admin.batches.destroy', $batch))->assertRedirect(route('pos.index'));

        $this->assertDatabaseHas('product_batches', ['id' => $batch->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::ACCESS_DENIED]);
    }

    public function test_staff_can_still_read_stock_levels(): void
    {
        $staff = User::factory()->staff()->create();
        $product = Product::factory()->create(['name' => 'Cola Can', 'stock' => 0]);
        $product->batches()->create(['expiry_date' => now()->addDays(8)->toDateString(), 'quantity' => 12]);
        $product->forceFill(['stock' => 12])->save();

        $this->actingAs($staff)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('Cola Can')
            ->assertSee('Expires in 8 days')
            // Read-only: no per-row adjust or lot links for a cashier.
            ->assertDontSee(route('admin.inventory.create', $product), false);
    }

    // ------------------------------------------------------------- helpers --

    public function test_expiry_status_buckets(): void
    {
        $product = Product::factory()->create(['stock' => 0]);

        $ok = $product->batches()->create(['expiry_date' => now()->addMonths(6)->toDateString(), 'quantity' => 1]);
        $this->assertSame('ok', $ok->status());
        $this->assertStringContainsString('Expires in', $ok->expiryLabel());
        $this->assertStringContainsString('Unlabelled lot', $ok->displayLabel());
        $this->assertStringContainsString('1 left', $ok->displayLabel());
        $this->assertTrue($ok->isSellable());

        $soon = $product->batches()->create(['expiry_date' => now()->addDays(10)->toDateString(), 'quantity' => 1]);
        $this->assertSame('expiring', $soon->status());
        $this->assertStringContainsString('Expires in 10 days', $soon->expiryLabel());
        $this->assertTrue($soon->isExpiringSoon());

        $gone = $product->batches()->create(['expiry_date' => now()->subDays(3)->toDateString(), 'quantity' => 1]);
        $this->assertSame('expired', $gone->status());
        $this->assertStringContainsString('Expired 3 days ago', $gone->expiryLabel());
        $this->assertSame(-3, $gone->daysUntilExpiry());
        $this->assertFalse($gone->isSellable(), 'An expired lot must never be sellable.');

        $undated = $product->batches()->create(['quantity' => 1]);
        $this->assertSame('none', $undated->status());
        $this->assertSame('No expiry', $undated->expiryLabel());
        $this->assertNull($undated->daysUntilExpiry());
        $this->assertTrue($undated->isSellable(), 'An undated lot never expires.');

        // The worst status across the lots wins, so a product holding one lapsed
        // lot is flagged even when it also has good stock.
        $product->forceFill(['stock' => 4])->save();
        $this->assertSame('expired', $product->fresh()->expiryStatus());
    }

    public function test_the_warning_window_is_thirty_days(): void
    {
        $this->assertSame(30, ProductBatch::EXPIRY_WARNING_DAYS);

        $product = Product::factory()->create(['stock' => 0]);
        $product->batches()->create([
            'expiry_date' => now()->addDays(ProductBatch::EXPIRY_WARNING_DAYS + 5)->toDateString(),
            'quantity' => 1,
        ]);
        $product->forceFill(['stock' => 1])->save();

        $this->assertSame(0, Product::expiringStock()->count());
        $this->assertSame(1, Product::whereHas('batches', fn ($q) => $q->expiringWithin(60))->count());
    }

    public function test_undated_top_ups_share_one_general_lot(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 0]);

        // A lot with no date, which the form cannot create directly - the
        // service maintains it for non-perishables and returns.
        $general = $product->batches()->create(['quantity' => 0]);

        // Adding to a named existing lot skips the date requirement entirely.
        $this->actingAs($admin)->post(route('admin.inventory.store', $product), [
            'type' => InventoryMovementType::StockIn->value,
            'quantity' => 5,
            'reason' => 'First top-up',
            'batch_id' => $general->id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.inventory.store', $product), [
            'type' => InventoryMovementType::StockIn->value,
            'quantity' => 3,
            'reason' => 'Second top-up',
            'batch_id' => $general->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('product_batches', 1);
        $this->assertSame(8, $general->fresh()->quantity);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertStockMatchesLots($product);
    }

    public function test_an_undated_top_up_is_refused_on_a_dated_product(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 0]);
        $product->batches()->create(['expiry_date' => now()->addMonths(3)->toDateString(), 'quantity' => 5]);
        $product->forceFill(['stock' => 5])->save();

        // No lot chosen and no date: the delivery could never be flagged.
        $this->actingAs($admin)
            ->post(route('admin.inventory.store', $product), [
                'type' => InventoryMovementType::StockIn->value,
                'quantity' => 5,
                'reason' => 'Delivery',
            ])
            ->assertSessionHasErrors('expiry_date');

        $this->assertSame(5, $product->fresh()->stock);
        $this->assertStockMatchesLots($product);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
