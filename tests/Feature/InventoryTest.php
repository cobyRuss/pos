<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
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

    public function test_restock_increases_stock_and_logs_the_movement(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($this->admin)
            ->post(route('admin.inventory.adjust.store', $product), [
                'action' => 'restock',
                'quantity' => 15,
                'notes' => 'Supplier delivery DLV-9',
            ])
            ->assertRedirect(route('inventory.index'))
            ->assertSessionHas('success');

        $this->assertSame(25, $product->fresh()->stock);

        $log = InventoryLog::where('product_id', $product->id)->sole();
        $this->assertSame(15, $log->quantity_change);
        $this->assertSame('in', $log->type);
        $this->assertSame('Supplier delivery DLV-9', $log->notes);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_write_off_reduces_stock_and_logs_the_movement(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($this->admin)->post(route('admin.inventory.adjust.store', $product), [
            'action' => 'write_off',
            'quantity' => 4,
            'notes' => 'Damaged in transit',
        ])->assertSessionHasNoErrors();

        $this->assertSame(6, $product->fresh()->stock);

        $log = InventoryLog::where('product_id', $product->id)->sole();
        $this->assertSame(-4, $log->quantity_change);
        $this->assertSame('out', $log->type);
        $this->assertSame('Damaged in transit', $log->notes);
    }

    public function test_write_off_cannot_exceed_available_stock(): void
    {
        $product = Product::factory()->create(['stock' => 3]);

        $this->actingAs($this->admin)->post(route('admin.inventory.adjust.store', $product), [
            'action' => 'write_off',
            'quantity' => 5,
        ])->assertSessionHasErrors('quantity');

        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame(0, InventoryLog::count());
    }

    public function test_set_makes_the_counted_quantity_the_new_absolute_level(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($this->admin)->post(route('admin.inventory.adjust.store', $product), [
            'action' => 'set',
            'quantity' => 42,
            'notes' => 'Year end count',
        ])->assertSessionHasNoErrors();

        $this->assertSame(42, $product->fresh()->stock);

        $log = InventoryLog::where('product_id', $product->id)->sole();
        $this->assertSame(32, $log->quantity_change);
        $this->assertSame('adjustment', $log->type);
        $this->assertSame('Year end count', $log->notes);
    }

    public function test_set_to_the_current_level_records_no_movement(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($this->admin)->post(route('admin.inventory.adjust.store', $product), [
            'action' => 'set',
            'quantity' => 10,
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(0, InventoryLog::count());
    }

    public function test_quantity_must_be_positive(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($this->admin)->post(route('admin.inventory.adjust.store', $product), [
            'action' => 'restock',
            'quantity' => 0,
        ])->assertSessionHasErrors('quantity');

        $this->actingAs($this->admin)->post(route('admin.inventory.adjust.store', $product), [
            'action' => 'restock',
            'quantity' => -5,
        ])->assertSessionHasErrors('quantity');
    }

    public function test_unknown_action_is_rejected(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($this->admin)->post(route('admin.inventory.adjust.store', $product), [
            'action' => 'delete_everything',
            'quantity' => 1,
        ])->assertSessionHasErrors('action');

        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_stock_adjustment_is_audited(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($this->admin)->post(route('admin.inventory.adjust.store', $product), [
            'action' => 'write_off',
            'quantity' => 2,
        ])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'inventory.adjusted')->sole();

        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(Product::class, $log->model_type);
        $this->assertSame($product->id, $log->model_id);
        $this->assertStringContainsString('write_off', $log->description);
    }

    public function test_inventory_page_shows_low_and_out_of_stock_products(): void
    {
        Product::factory()->create(['name' => 'Healthy Item', 'stock' => 40, 'low_stock_threshold' => 5]);
        Product::factory()->lowStock()->create(['name' => 'Running Low', 'stock' => 2, 'low_stock_threshold' => 5]);
        Product::factory()->outOfStock()->create(['name' => 'Sold Out', 'stock' => 0, 'low_stock_threshold' => 5]);

        $this->actingAs($this->staff)->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('Running Low')
            ->assertSee('Sold Out')
            ->assertSee('Healthy Item');

        $this->actingAs($this->staff)->get(route('inventory.index', ['status' => 'out_of_stock']))
            ->assertOk()
            ->assertSee('Sold Out')
            ->assertDontSee('Running Low');
    }

    public function test_inventory_page_filters_by_category_and_search(): void
    {
        $drinks = Category::factory()->create(['name' => 'Drinks']);
        $snacks = Category::factory()->create(['name' => 'Snacks']);

        Product::factory()->create(['name' => 'Cola', 'category_id' => $drinks->id]);
        Product::factory()->create(['name' => 'Crisps', 'category_id' => $snacks->id]);

        $this->actingAs($this->staff)->get(route('inventory.index', ['category' => $drinks->id]))
            ->assertOk()
            ->assertSee('Cola')
            ->assertDontSee('Crisps');

        $this->actingAs($this->staff)->get(route('inventory.index', ['search' => 'Crisps']))
            ->assertOk()
            ->assertSee('Crisps')
            ->assertDontSee('Cola');
    }

    public function test_inventory_movement_log_lists_movements_and_can_be_filtered(): void
    {
        $cola = Product::factory()->create(['name' => 'Cola', 'stock' => 5]);
        $crisps = Product::factory()->create(['name' => 'Crisps', 'stock' => 5]);

        // Unique notes double as proof that a given movement is on the page;
        // the product filter dropdown always lists every product name, so the
        // names themselves cannot be used to assert exclusion.
        $this->actingAs($this->admin)->post(route('admin.inventory.adjust.store', $cola), [
            'action' => 'restock',
            'quantity' => 10,
            'notes' => 'cola-delivery',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('admin.inventory.adjust.store', $crisps), [
            'action' => 'write_off',
            'quantity' => 2,
            'notes' => 'crisps-expired',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('inventory.logs'))
            ->assertOk()
            ->assertSee('cola-delivery')
            ->assertSee('crisps-expired');

        $this->actingAs($this->admin)->get(route('inventory.logs', ['product' => $cola->id]))
            ->assertOk()
            ->assertSee('cola-delivery')
            ->assertDontSee('crisps-expired');

        $this->actingAs($this->admin)->get(route('inventory.logs', ['type' => 'out']))
            ->assertOk()
            ->assertSee('crisps-expired')
            ->assertDontSee('cola-delivery');
    }

    public function test_staff_can_view_inventory_but_cannot_adjust_it(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        // Balances are readable by staff; the movement log and the adjust
        // forms are admin-only, matching the inventory.view/adjust split.
        $this->actingAs($this->staff)->get(route('inventory.index'))->assertOk();
        $this->actingAs($this->staff)->get(route('inventory.logs'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');

        $this->actingAs($this->staff)->get(route('admin.inventory.adjust.create', $product))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');

        $this->actingAs($this->staff)->post(route('admin.inventory.adjust.store', $product), [
            'action' => 'restock',
            'quantity' => 100,
        ])->assertSessionHas('error');

        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_inventory_page_hides_the_adjust_controls_from_staff(): void
    {
        Product::factory()->create(['stock' => 10]);

        $this->actingAs($this->staff)->get(route('inventory.index'))
            ->assertOk()
            ->assertDontSee(route('admin.inventory.adjust.create', Product::first()));

        $this->actingAs($this->admin)->get(route('inventory.index'))
            ->assertOk()
            ->assertSee(route('admin.inventory.adjust.create', Product::first()));
    }

    public function test_stock_cannot_be_driven_negative_through_the_service(): void
    {
        $product = Product::factory()->create(['stock' => 2]);
        $service = app(InventoryService::class);

        try {
            $service->writeOff($product, 5, $this->admin, 'too much');
            $this->fail('Expected the write-off to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient stock', $e->getMessage());
        }

        // The failed movement must leave no trace.
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, InventoryLog::count());
    }

    public function test_service_records_movement_for_a_direct_call(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $service = app(InventoryService::class);

        $service->restock($product, 12, $this->admin, 'Direct call');

        $this->assertSame(12, $product->fresh()->stock);

        $log = InventoryLog::where('product_id', $product->id)->sole();
        $this->assertSame(12, $log->quantity_change);
        $this->assertSame('in', $log->type);
        $this->assertSame('Direct call', $log->notes);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_service_accepts_a_null_actor_for_system_movements(): void
    {
        $product = Product::factory()->create(['stock' => 4]);

        app(InventoryService::class)->restock($product, 1);

        $log = InventoryLog::where('product_id', $product->id)->sole();
        $this->assertNull($log->user_id);
        $this->assertSame(5, $product->fresh()->stock);
    }
}
