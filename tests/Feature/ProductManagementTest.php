<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductManagementTest extends TestCase
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

    public function test_admin_can_create_a_product_with_opening_stock(): void
    {
        $category = Category::factory()->create(['name' => 'Drinks']);

        $response = $this->actingAs($this->admin)->post(route('admin.products.store'), [
            'name' => 'Cola 500ml',
            'sku' => 'DRK-001',
            'category_id' => $category->id,
            'price' => '2.50',
            'cost' => '1.20',
            'stock' => 24,
            'low_stock_threshold' => 5,
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.products.index'));

        $product = Product::where('sku', 'DRK-001')->firstOrFail();

        // Stock must come from the inventory service, not the insert, or the
        // opening quantity would be doubled.
        $this->assertSame(24, $product->stock);
        $this->assertSame('Cola 500ml', $product->name);
        $this->assertSame('2.50', $product->price);
        $this->assertTrue($product->is_active);

        $log = InventoryLog::where('product_id', $product->id)->sole();
        $this->assertSame(24, $log->quantity_change);
        $this->assertSame('in', $log->type);
        $this->assertSame('Opening stock', $log->notes);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_creating_a_product_with_zero_stock_writes_no_log(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), [
            'name' => 'Empty',
            'sku' => 'EMP-001',
            'price' => '1.00',
            'stock' => 0,
            'low_stock_threshold' => 5,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, Product::where('sku', 'EMP-001')->sole()->stock);
        $this->assertSame(0, InventoryLog::count());
    }

    public function test_creating_a_product_is_audited(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), [
            'name' => 'Audited Product',
            'sku' => 'AUD-001',
            'price' => '5.00',
            'stock' => 1,
            'low_stock_threshold' => 5,
        ]);

        $log = AuditLog::where('action', 'product.created')->sole();

        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(Product::class, $log->model_type);
        $this->assertStringContainsString('AUD-001', $log->description);
    }

    public function test_staff_cannot_create_a_product(): void
    {
        // Browser requests are bounced with a flash alert rather than shown a
        // bare 403 page, per the security spec.
        $this->actingAs($this->staff)
            ->post(route('admin.products.store'), [
                'name' => 'Sneaky',
                'sku' => 'SNK-001',
                'price' => '1.00',
                'stock' => 1,
                'low_stock_threshold' => 1,
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('products', ['sku' => 'SNK-001']);
    }

    public function test_staff_cannot_create_a_product_via_json(): void
    {
        $this->actingAs($this->staff)
            ->postJson(route('admin.products.store'), [
                'name' => 'Sneaky',
                'sku' => 'SNK-001',
                'price' => '1.00',
                'stock' => 1,
                'low_stock_threshold' => 1,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized action.');

        $this->assertDatabaseMissing('products', ['sku' => 'SNK-001']);
    }

    public function test_sku_must_be_unique(): void
    {
        Product::factory()->create(['sku' => 'TAKEN']);

        $this->actingAs($this->admin)->post(route('admin.products.store'), [
            'name' => 'Clash',
            'sku' => 'TAKEN',
            'price' => '1.00',
            'stock' => 0,
            'low_stock_threshold' => 1,
        ])->assertSessionHasErrors('sku');
    }

    public function test_cost_cannot_exceed_price(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), [
            'name' => 'Bad margin',
            'sku' => 'BAD-001',
            'price' => '5.00',
            'cost' => '9.00',
            'stock' => 0,
            'low_stock_threshold' => 1,
        ])->assertSessionHasErrors('cost');
    }

    public function test_barcode_must_have_a_valid_check_digit(): void
    {
        $this->actingAs($this->admin)->post(route('admin.products.store'), [
            'name' => 'Bad barcode',
            'sku' => 'BC-001',
            'barcode' => '4006381333931', // valid EAN-13
            'price' => '1.00',
            'stock' => 0,
            'low_stock_threshold' => 1,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('admin.products.store'), [
            'name' => 'Bad barcode',
            'sku' => 'BC-002',
            'barcode' => '4006381333930', // same digits, wrong check digit
            'price' => '1.00',
            'stock' => 0,
            'low_stock_threshold' => 1,
        ])->assertSessionHasErrors('barcode');
    }

    public function test_updating_stock_from_the_product_form_records_an_adjustment(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($this->admin)->put(route('admin.products.update', $product), [
            'name' => $product->name,
            'sku' => $product->sku,
            'price' => $product->price,
            'stock' => 7,
            'low_stock_threshold' => 5,
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(7, $product->fresh()->stock);

        $log = InventoryLog::where('product_id', $product->id)->sole();
        $this->assertSame(-3, $log->quantity_change);
        $this->assertSame('adjustment', $log->type);
    }

    public function test_saving_the_product_form_without_changing_stock_writes_no_log(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($this->admin)->put(route('admin.products.update', $product), [
            'name' => 'Renamed',
            'sku' => $product->sku,
            'price' => $product->price,
            'stock' => 10,
            'low_stock_threshold' => 5,
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $product->fresh()->name);
        $this->assertSame(0, InventoryLog::count());
    }

    public function test_unchecking_is_active_deactivates_the_product(): void
    {
        $product = Product::factory()->create(['is_active' => true]);

        // "is_active" absent entirely, as a browser sends for an unticked box.
        $this->actingAs($this->admin)->put(route('admin.products.update', $product), [
            'name' => $product->name,
            'sku' => $product->sku,
            'price' => $product->price,
            'stock' => $product->stock,
            'low_stock_threshold' => 5,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($product->fresh()->is_active);
    }

    public function test_image_is_stored_on_the_public_disk(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post(route('admin.products.store'), [
            'name' => 'With Image',
            'sku' => 'IMG-001',
            'price' => '1.00',
            'stock' => 0,
            'low_stock_threshold' => 1,
            'image' => UploadedFile::fake()->image('cola.jpg'),
        ])->assertSessionHasNoErrors();

        $product = Product::where('sku', 'IMG-001')->sole();

        $this->assertNotNull($product->image);
        Storage::disk('public')->assertExists($product->image);
    }

    public function test_replacing_an_image_deletes_the_previous_file(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create();
        $product->update(['image' => 'products/old.jpg']);
        Storage::disk('public')->put('products/old.jpg', 'x');

        $this->actingAs($this->admin)->put(route('admin.products.update', $product), [
            'name' => $product->name,
            'sku' => $product->sku,
            'price' => $product->price,
            'stock' => $product->stock,
            'low_stock_threshold' => 5,
            'image' => UploadedFile::fake()->image('new.jpg'),
        ])->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing('products/old.jpg');
        Storage::disk('public')->assertExists($product->fresh()->image);
    }

    public function test_a_product_with_stock_cannot_be_archived(): void
    {
        $product = Product::factory()->create(['stock' => 3, 'is_active' => true]);

        $this->actingAs($this->admin)
            ->delete(route('admin.products.destroy', $product))
            ->assertSessionHas('error');

        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_a_product_with_zero_stock_can_be_archived(): void
    {
        $product = Product::factory()->outOfStock()->create(['is_active' => true]);

        $this->actingAs($this->admin)->delete(route('admin.products.destroy', $product));

        // Archiving flips is_active and keeps the row, so order history keeps
        // a resolvable product reference.
        $this->assertFalse($product->fresh()->is_active);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => false]);
    }

    public function test_staff_can_browse_products(): void
    {
        Product::factory()->create(['name' => 'Cola 500ml', 'sku' => 'DRK-001']);
        Product::factory()->inactive()->create(['name' => 'Discontinued Item', 'sku' => 'OLD-001']);

        $this->actingAs($this->staff)
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('Cola 500ml')
            ->assertDontSee('Discontinued Item');
    }

    public function test_product_search_matches_name_sku_and_barcode(): void
    {
        $cola = Product::factory()->withBarcode('4006381333931')->create([
            'name' => 'Cola 500ml',
            'sku' => 'DRK-001',
        ]);

        $this->actingAs($this->staff)->get(route('products.index', ['search' => 'Cola']))
            ->assertOk()->assertSee('Cola 500ml');

        $this->actingAs($this->staff)->get(route('products.index', ['search' => 'DRK-001']))
            ->assertOk()->assertSee('Cola 500ml');

        $this->actingAs($this->staff)->get(route('products.index', ['search' => '4006381333931']))
            ->assertOk()->assertSee('Cola 500ml');

        $this->actingAs($this->staff)->get(route('products.index', ['search' => 'Cola']))
            ->assertOk()->assertSee($cola->name);
    }

    public function test_products_can_be_filtered_by_category(): void
    {
        $drinks = Category::factory()->create(['name' => 'Drinks']);
        $snacks = Category::factory()->create(['name' => 'Snacks']);

        Product::factory()->create(['name' => 'Cola', 'category_id' => $drinks->id]);
        Product::factory()->create(['name' => 'Crisps', 'category_id' => $snacks->id]);

        $this->actingAs($this->staff)
            ->get(route('products.index', ['category' => $drinks->id]))
            ->assertOk()
            ->assertSee('Cola')
            ->assertDontSee('Crisps');
    }

    public function test_archived_product_detail_page_is_not_reachable(): void
    {
        $product = Product::factory()->inactive()->create();

        $this->actingAs($this->staff)
            ->get(route('products.show', $product))
            ->assertNotFound();
    }

    public function test_staff_cannot_open_the_admin_product_pages(): void
    {
        $product = Product::factory()->create();

        foreach ([
            route('admin.products.index'),
            route('admin.products.create'),
            route('admin.products.edit', $product),
        ] as $url) {
            $this->actingAs($this->staff)->get($url)
                ->assertRedirect(route('dashboard'))
                ->assertSessionHas('error');

            $this->actingAs($this->staff)->getJson($url)
                ->assertForbidden();
        }
    }
}
