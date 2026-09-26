<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
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

    public function test_admin_can_create_a_category(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.categories.store'), [
                'name' => 'Drinks',
                'description' => 'Cold beverages',
            ])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('categories', ['name' => 'Drinks', 'is_active' => true]);
    }

    public function test_category_name_must_be_unique(): void
    {
        Category::factory()->create(['name' => 'Drinks']);

        $this->actingAs($this->admin)
            ->post(route('admin.categories.store'), ['name' => 'Drinks'])
            ->assertSessionHasErrors('name');
    }

    public function test_category_name_must_be_unique_ignoring_case(): void
    {
        Category::factory()->create(['name' => 'Drinks']);

        $this->actingAs($this->admin)
            ->post(route('admin.categories.store'), ['name' => 'drinks'])
            ->assertSessionHasErrors('name');
    }

    public function test_renaming_a_category_ignores_itself_for_uniqueness(): void
    {
        $category = Category::factory()->create(['name' => 'Drinks']);

        $this->actingAs($this->admin)
            ->put(route('admin.categories.update', $category), ['name' => 'Drinks'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Drinks', $category->fresh()->name);
    }

    public function test_category_can_be_deactivated_without_deleting_it(): void
    {
        $category = Category::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)
            ->put(route('admin.categories.update', $category), [
                'name' => $category->name,
                'is_active' => '0',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($category->fresh()->is_active);
    }

    public function test_deactivating_a_category_keeps_its_products_but_hides_it_from_new_sales(): void
    {
        $category = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create(['category_id' => $category->id]);

        $this->actingAs($this->admin)->put(route('admin.categories.update', $category), [
            'name' => $category->name,
            'is_active' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($product->fresh()->category_id);
        $this->assertFalse($category->fresh()->is_active);
    }

    public function test_deleting_a_category_orphans_its_products_rather_than_deleting_them(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'category_id' => null]);
    }

    public function test_category_changes_are_audited(): void
    {
        $category = Category::factory()->create(['name' => 'Snacks']);

        $this->actingAs($this->admin)->put(route('admin.categories.update', $category), [
            'name' => 'Confectionery',
        ])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'category.updated')->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(Category::class, $log->model_type);
        $this->assertStringContainsString('Confectionery', $log->description);
    }

    public function test_staff_cannot_manage_categories(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->staff)->get(route('admin.categories.index'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');

        $this->actingAs($this->staff)->get(route('admin.categories.edit', $category))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($this->staff)->post(route('admin.categories.store'), ['name' => 'Nope'])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('categories', ['name' => 'Nope']);
    }

    public function test_only_active_categories_are_offered_in_the_product_form(): void
    {
        Category::factory()->create(['name' => 'Drinks', 'is_active' => true]);
        Category::factory()->create(['name' => 'Retired', 'is_active' => false]);

        $response = $this->actingAs($this->admin)->get(route('admin.products.create'));

        $response->assertOk()->assertSee('Drinks')->assertDontSee('Retired');
    }
}
