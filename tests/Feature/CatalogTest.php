<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sparkling Water',
            'category_id' => Category::factory()->create()->id,
            'cost_price' => 0.60,
            'selling_price' => 1.25,
            'stock' => 24,
            'low_stock_threshold' => 6,
            'unit' => 'pcs',
            'is_active' => 1,
            // The opening stock becomes a delivery lot, and a lot needs its date.
            'expiry_date' => now()->addMonths(6)->toDateString(),
        ], $overrides);
    }

    public function test_an_admin_can_create_a_product_with_a_category(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['name' => 'Drinks']);

        $this->actingAs($admin)
            ->post(route('admin.products.store'), $this->payload(['category_id' => $category->id]))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'name' => 'Sparkling Water',
            'selling_price' => 1.25,
            'cost_price' => 0.60,
            'stock' => 24,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::PRODUCT_CREATED]);
    }

    public function test_creating_a_product_records_the_opening_stock(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.products.store'), $this->payload());

        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => Product::sole()->id,
            'before_stock' => 0,
            'after_stock' => 24,
        ]);
    }

    public function test_a_product_carries_an_optional_unique_numeric_barcode(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.products.store'), $this->payload([
            'name' => 'Cola Can',
            'barcode' => '4801101000014',
        ]))->assertRedirect();

        $this->assertDatabaseHas('products', ['name' => 'Cola Can', 'barcode' => '4801101000014']);

        // The same printed code on a second product would make a scan ambiguous,
        // so it is refused.
        $this->actingAs($admin)
            ->post(route('admin.products.store'), $this->payload([
                'name' => 'Cola Can Duplicate',
                'barcode' => '4801101000014',
            ]))
            ->assertSessionHasErrors('barcode');

        // Barcodes are digits. A product with no printed code stores null, and
        // null must not collide with the next product that also has none.
        $this->actingAs($admin)
            ->post(route('admin.products.store'), $this->payload([
                'name' => 'Loose Bananas',
                'barcode' => '',
            ]))
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.products.store'), $this->payload([
                'name' => 'Loose Apples',
                'barcode' => '',
            ]))
            ->assertRedirect();

        $this->assertSame(2, Product::whereNull('barcode')->count());
    }

    public function test_a_product_still_carries_no_sku(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.products.store'), $this->payload());

        $this->assertFalse(
            Schema::hasColumn('products', 'sku'),
            'The sku column should no longer exist.'
        );
        $this->assertFalse(Schema::hasColumn('order_items', 'sku'));

        // A payload that still carries the old fields must be ignored, not stored.
        $this->actingAs($admin)->post(route('admin.products.store'), $this->payload([
            'name' => 'Legacy Payload',
            'sku' => 'OLD-001',
        ]))->assertRedirect();

        $legacy = Product::where('name', 'Legacy Payload')->sole();
        $this->assertArrayNotHasKey('sku', $legacy->getAttributes());
    }

    public function test_search_only_matches_name_and_description(): void
    {
        $admin = User::factory()->admin()->create();
        $cola = Product::factory()->create([
            'name' => 'Cola Can',
            'description' => 'Fizzy classic',
            'barcode' => '4801101000014',
        ]);
        Product::factory()->create(['name' => 'Crisps', 'description' => 'Salted snack']);

        $this->actingAs($admin)
            ->get(route('products.index', ['q' => 'Cola']))
            ->assertOk()
            ->assertSee($cola->name)
            ->assertDontSee('Crisps');

        $this->actingAs($admin)
            ->get(route('products.index', ['q' => 'Fizzy']))
            ->assertOk()
            ->assertSee('Cola Can');

        // The old SKU is still not searchable, because it no longer exists.
        $this->actingAs($admin)
            ->get(route('products.index', ['q' => 'SKU-1']))
            ->assertOk()
            ->assertDontSee($cola->name);

        // A printed barcode typed or pasted into the search box finds its
        // product, so a code the camera cannot read is still recoverable.
        $this->actingAs($admin)
            ->get(route('products.index', ['q' => '4801101000014']))
            ->assertOk()
            ->assertSee('Cola Can');
    }

    public function test_core_fields_are_validated(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.products.store'), [
                'name' => '',
                'cost_price' => -1,
                'selling_price' => -2,
                'stock' => -3,
                'low_stock_threshold' => -4,
                'unit' => '',
            ])
            ->assertSessionHasErrors(['name', 'cost_price', 'selling_price', 'stock', 'low_stock_threshold', 'unit']);
    }

    public function test_selling_below_cost_is_flagged(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.products.store'), $this->payload(['cost_price' => 5, 'selling_price' => 1]))
            ->assertSessionHasErrors('selling_price');
    }

    public function test_a_product_can_be_renamed_without_moving_stock(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->priced(1, 2)->create(['name' => 'Old Name', 'stock' => 7]);

        $this->actingAs($admin)
            ->put(route('admin.products.update', $product), $this->payload([
                'name' => 'New Name',
                'selling_price' => 2.50,
                'stock' => 7,
            ]))
            ->assertRedirect(route('products.index'));

        $product->refresh();

        $this->assertSame('New Name', $product->name);
        $this->assertSame(7, $product->stock);
        $this->assertSame(0, $product->inventoryMovements()->count(), 'An unchanged stock level must not log a movement.');
    }

    public function test_changing_stock_on_the_edit_form_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 7]);

        $this->actingAs($admin)
            ->put(route('admin.products.update', $product), $this->payload(['stock' => 12]));

        $this->assertSame(12, $product->fresh()->stock);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'before_stock' => 7,
            'after_stock' => 12,
        ]);
    }

    public function test_deactivating_a_product_hides_it_from_the_till(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 2)->create(['name' => 'Discontinued', 'stock' => 5]);

        $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertOk();

        $this->actingAs($admin)->put(route('admin.products.update', $product), $this->payload([
            'name' => $product->name,
            'stock' => $product->stock,
            'is_active' => 0,
        ]));

        $this->assertFalse($product->fresh()->is_active);

        $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertStatus(422);
    }

    public function test_a_product_can_be_deleted_when_it_was_never_sold(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create();

        $this->actingAs($admin)
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::PRODUCT_DELETED]);
    }

    public function test_a_sold_product_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $sold = Product::factory()->create();

        Order::factory()->create()->items()->create([
            'product_id' => $sold->id,
            'product_name' => $sold->name,
            'quantity' => 1,
            'unit_price' => $sold->selling_price,
            'discount_amount' => 0,
            'line_total' => $sold->selling_price,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.products.destroy', $sold))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('products', ['id' => $sold->id]);
    }

    public function test_the_product_list_can_be_searched_and_filtered(): void
    {
        $admin = User::factory()->admin()->create();
        $drinks = Category::factory()->create(['name' => 'Drinks']);
        $snacks = Category::factory()->create(['name' => 'Snacks']);

        $cola = Product::factory()->lowStock(3)->create(['name' => 'Cola Can', 'category_id' => $drinks->id]);
        $crisps = Product::factory()->create(['name' => 'Crisps', 'category_id' => $snacks->id, 'stock' => 40]);
        $retired = Product::factory()->inactive()->create(['name' => 'Retired Thing']);

        $this->actingAs($admin)
            ->get(route('products.index', ['q' => 'Cola']))
            ->assertOk()
            ->assertSee($cola->name)
            ->assertDontSee('Crisps');

        $this->actingAs($admin)
            ->get(route('products.index', ['category_id' => $snacks->id]))
            ->assertOk()
            ->assertSee($crisps->name)
            ->assertDontSee($cola->name);

        $this->actingAs($admin)
            ->get(route('products.index', ['status' => 'low']))
            ->assertOk()
            ->assertSee($cola->name)
            ->assertDontSee('Crisps');

        $this->actingAs($admin)
            ->get(route('products.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee($retired->name);

        $this->actingAs($admin)
            ->get(route('products.index', ['status' => 'bogus']))
            ->assertSessionHasErrors('status');
    }

    public function test_the_product_list_can_be_sorted(): void
    {
        $admin = User::factory()->admin()->create();
        Product::factory()->priced(1, 1)->create(['name' => 'Alpha', 'stock' => 30]);
        Product::factory()->priced(1, 1)->create(['name' => 'Zulu', 'stock' => 5]);

        $this->actingAs($admin)->get(route('products.index', ['sort' => 'stock', 'direction' => 'desc']))->assertOk();

        $content = $this->actingAs($admin)->get(route('products.index'))->getContent();
        $this->assertLessThan(strpos($content, 'Zulu'), strpos($content, 'Alpha'));
    }

    public function test_product_pages_render(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['name' => 'Rendered Item']);

        $this->actingAs($admin)->get(route('products.index'))->assertOk()->assertSee('Rendered Item');
        $this->actingAs($admin)->get(route('admin.products.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.products.edit', $product))->assertOk();
        $this->actingAs($admin)->get(route('products.show', $product))->assertOk()->assertSee('Rendered Item');
    }

    public function test_staff_can_browse_the_catalog_but_not_manage_it(): void
    {
        $staff = User::factory()->staff()->create();
        $product = Product::factory()->create();

        $this->actingAs($staff)->get(route('products.index'))->assertOk();
        $this->actingAs($staff)->get(route('products.show', $product))->assertOk();

        $this->actingAs($staff)->get(route('admin.products.create'))->assertRedirect(route('pos.index'));
        $this->actingAs($staff)->get(route('admin.products.edit', $product))->assertRedirect(route('pos.index'));
        $this->actingAs($staff)->post(route('admin.products.store'), $this->payload())->assertRedirect(route('pos.index'));
        $this->actingAs($staff)->put(route('admin.products.update', $product), $this->payload())->assertRedirect(route('pos.index'));
        $this->actingAs($staff)->delete(route('admin.products.destroy', $product))->assertRedirect(route('pos.index'));

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_categories_can_be_created_renamed_and_deleted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), ['name' => 'Bakery', 'description' => 'Fresh daily', 'is_active' => 1])
            ->assertRedirect(route('admin.categories.index'));

        $category = Category::where('name', 'Bakery')->sole();
        $this->assertSame('Fresh daily', $category->description);

        $this->actingAs($admin)
            ->put(route('admin.categories.update', $category), ['name' => 'Bakery & Bread', 'is_active' => 1])
            ->assertRedirect(route('admin.categories.index'));

        $this->assertSame('Bakery & Bread', $category->fresh()->name);

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::CATEGORY_DELETED]);
    }

    public function test_a_category_name_must_be_unique(): void
    {
        $admin = User::factory()->admin()->create();
        Category::factory()->create(['name' => 'Bakery']);
        $other = Category::factory()->create(['name' => 'Produce']);

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), ['name' => 'Bakery'])
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->put(route('admin.categories.update', $other), ['name' => 'Bakery'])
            ->assertSessionHasErrors('name');
    }

    public function test_saving_a_category_under_its_own_name_is_allowed(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['name' => 'Bakery']);

        $this->actingAs($admin)
            ->put(route('admin.categories.update', $category), ['name' => 'Bakery', 'description' => 'Just tweaked'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Just tweaked', $category->fresh()->description);
    }

    public function test_a_category_with_products_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id]);

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_category_pages_render(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['name' => 'Bakery']);

        $this->actingAs($admin)->get(route('admin.categories.index'))->assertOk()->assertSee('Bakery');
        $this->actingAs($admin)->get(route('admin.categories.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.categories.edit', $category))->assertOk();
    }

    public function test_staff_cannot_manage_categories(): void
    {
        $staff = User::factory()->staff()->create();
        $category = Category::factory()->create();

        $this->actingAs($staff)->get(route('admin.categories.index'))->assertRedirect(route('pos.index'));
        $this->actingAs($staff)->post(route('admin.categories.store'), ['name' => 'Nope'])->assertRedirect(route('pos.index'));
        $this->actingAs($staff)->put(route('admin.categories.update', $category), ['name' => 'Nope'])->assertRedirect(route('pos.index'));
        $this->actingAs($staff)->delete(route('admin.categories.destroy', $category))->assertRedirect(route('pos.index'));

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }
}
