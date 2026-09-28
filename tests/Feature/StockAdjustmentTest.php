<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_add_stock_and_it_is_logged(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 10, 'low_stock_threshold' => 5]);
        $expiry = now()->addMonths(6)->toDateString();

        $this->actingAs($admin)
            ->post(route('admin.inventory.store', $product), [
                'type' => InventoryMovementType::StockIn->value,
                'quantity' => 25,
                'reason' => 'Supplier delivery',
                'batch_no' => 'LOT-4412',
                'expiry_date' => $expiry,
            ])
            ->assertRedirect();

        $this->assertSame(35, $product->fresh()->stock);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'type' => InventoryMovementType::StockIn->value,
            'quantity' => 25,
            'before_stock' => 10,
            'after_stock' => 35,
            'reason' => 'Supplier delivery',
            'user_id' => $admin->id,
        ]);

        // The date the units were received under is recorded against the lot and
        // snapshotted onto the movement, so the log stands on its own.
        $batch = $product->batches()->whereNotNull('expiry_date')->sole();
        $this->assertSame(25, $batch->quantity);
        $this->assertSame('LOT-4412', $batch->batch_no);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'expiry_date' => $expiry,
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::STOCK_ADJUSTED]);
    }

    public function test_a_stock_in_needs_an_expiry_date_or_an_existing_lot(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 10]);

        // Neither a lot nor a date: the delivery could never be flagged as
        // expiring, so it is refused.
        $this->actingAs($admin)
            ->post(route('admin.inventory.store', $product), [
                'type' => InventoryMovementType::StockIn->value,
                'quantity' => 5,
                'reason' => 'Supplier delivery',
            ])
            ->assertSessionHasErrors('expiry_date');

        $this->assertSame(10, $product->fresh()->stock);

        // A date in the past is refused too.
        $this->actingAs($admin)
            ->post(route('admin.inventory.store', $product), [
                'type' => InventoryMovementType::StockIn->value,
                'quantity' => 5,
                'reason' => 'Supplier delivery',
                'expiry_date' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('expiry_date');
    }

    public function test_stock_out_reduces_the_available_quantity(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($admin)
            ->post(route('admin.inventory.store', $product), [
                'type' => InventoryMovementType::StockOut->value,
                'quantity' => 4,
                'reason' => 'Damaged in storage',
            ]);

        $this->assertSame(6, $product->fresh()->stock);
    }

    public function test_an_absolute_adjustment_logs_the_difference(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 18]);

        $this->actingAs($admin)
            ->post(route('admin.inventory.store', $product), [
                'type' => InventoryMovementType::Adjustment->value,
                'new_stock' => 12,
                'reason' => 'Stock count correction',
            ]);

        $this->assertSame(12, $product->fresh()->stock);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'type' => InventoryMovementType::Adjustment->value,
            'quantity' => -6,
            'before_stock' => 18,
            'after_stock' => 12,
        ]);
    }

    public function test_stock_cannot_be_driven_below_zero(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 3]);

        $this->actingAs($admin)
            ->post(route('admin.inventory.store', $product), [
                'type' => InventoryMovementType::StockOut->value,
                'quantity' => 10,
                'reason' => 'Shrinkage',
            ])
            ->assertSessionHasErrors();

        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_a_reason_is_required_for_every_movement(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($admin)
            ->post(route('admin.inventory.store', $product), [
                'type' => InventoryMovementType::StockIn->value,
                'quantity' => 1,
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_staff_can_view_stock_levels_but_cannot_adjust_them(): void
    {
        $staff = User::factory()->staff()->create();
        $product = Product::factory()->lowStock(2)->create();

        $this->actingAs($staff)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee($product->name);

        // The role middleware bounces staff back to the till with a message.
        $this->actingAs($staff)
            ->post(route('admin.inventory.store', $product), [
                'type' => InventoryMovementType::StockIn->value,
                'quantity' => 5,
                'reason' => 'Nope',
            ])
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('error');

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertDatabaseMissing('inventory_movements', ['product_id' => $product->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::ACCESS_DENIED]);
    }

    public function test_the_movement_log_can_be_filtered(): void
    {
        $admin = User::factory()->admin()->create();
        $cola = Product::factory()->create(['name' => 'Cola Can', 'stock' => 5]);
        $soap = Product::factory()->create(['name' => 'Dish Soap', 'stock' => 5]);

        $this->actingAs($admin)->post(route('admin.inventory.store', $cola), [
            'type' => InventoryMovementType::StockIn->value,
            'quantity' => 10,
            'reason' => 'Delivery',
            'expiry_date' => now()->addMonths(3)->toDateString(),
        ]);

        $this->actingAs($admin)->post(route('admin.inventory.store', $soap), [
            'type' => InventoryMovementType::StockOut->value,
            'quantity' => 2,
            'reason' => 'Breakage',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.inventory.movements', ['type' => InventoryMovementType::StockOut->value]))
            ->assertOk()
            ->assertSee('Breakage')
            ->assertDontSee('Delivery');

        $this->assertSame(2, InventoryMovement::count());
    }

    public function test_the_adjust_form_renders_with_a_live_preview(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 7]);

        $this->actingAs($admin)
            ->get(route('admin.inventory.create', $product))
            ->assertOk()
            ->assertSee('Apply Movement')
            ->assertSee('data-current-stock="7"', false);
    }
}
