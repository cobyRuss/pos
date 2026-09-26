<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards against a controller pointing at a view that does not exist and
 * against forms rendering the wrong control for a field.
 */
class PageRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin);
    }

    public static function adminPageProvider(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'product discovery' => ['products.index'],
            'product detail' => ['products.show'],
            'admin products' => ['admin.products.index'],
            'new product' => ['admin.products.create'],
            'admin categories' => ['admin.categories.index'],
            'new category' => ['admin.categories.create'],
            'inventory balances' => ['inventory.index'],
            'inventory movement log' => ['inventory.logs'],
            'pos terminal' => ['pos.index'],
            'order history' => ['orders.index'],
            'refunds' => ['admin.refunds.index'],
            'staff' => ['admin.staff.index'],
            'new staff' => ['admin.staff.create'],
            'reports' => ['admin.reports.index'],
            'audit log' => ['admin.audit-logs.index'],
            'settings' => ['admin.settings.edit'],
        ];
    }

    #[DataProvider('adminPageProvider')]
    public function test_every_page_renders_for_an_admin(string $page): void
    {
        $product = Product::factory()->create(['category_id' => Category::factory()]);

        $this->actingAs($this->admin)
            ->get(route($page, $page === 'products.show' ? ['product' => $product] : []))
            ->assertOk()
            ->assertSee('</html>', false);
    }

    public function test_the_order_detail_and_receipt_render(): void
    {
        $order = Order::factory()->create(['user_id' => $this->admin->id]);

        $this->actingAs($this->admin)->get(route('orders.show', $order))->assertOk();
        $this->actingAs($this->admin)->get(route('pos.receipt', $order))->assertOk();
    }

    public function test_the_staff_edit_and_sales_report_pages_render(): void
    {
        $member = User::factory()->create();
        $member->assignRole(Role::Staff);

        $this->actingAs($this->admin)->get(route('admin.staff.edit', $member))->assertOk();

        $this->actingAs($this->admin)->get(route('admin.reports.sales', [
            'from' => now()->subWeek()->toDateString(),
            'to' => now()->toDateString(),
        ]))->assertOk();
    }

    public function test_edit_pages_render(): void
    {
        $product = Product::factory()->create();
        $category = Category::factory()->create();

        $this->actingAs($this->admin)->get(route('admin.products.edit', $product))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.categories.edit', $category))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.inventory.adjust.create', $product))->assertOk();
    }

    public function test_description_fields_render_a_textarea(): void
    {
        $this->actingAs($this->admin)->get(route('admin.products.create'))
            ->assertOk()
            ->assertSee('<textarea', false)
            ->assertSee('name="description"', false)
            ->assertDontSee('type="textarea"', false);

        $this->actingAs($this->admin)->get(route('admin.categories.create'))
            ->assertOk()
            ->assertSee('<textarea', false)
            ->assertDontSee('type="textarea"', false);
    }

    /**
     * The catalogue, category, inventory, POS and order links are built and
     * must resolve. Links for later phases are intentionally still inert.
     */
    public function test_built_navigation_links_resolve(): void
    {
        $response = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();

        foreach ([
            route('pos.index'),
            route('products.index'),
            route('inventory.index'),
            route('orders.index'),
            route('admin.products.index'),
            route('admin.categories.index'),
            route('admin.refunds.index'),
            route('admin.staff.index'),
            route('admin.reports.index'),
            route('admin.audit-logs.index'),
            route('admin.settings.edit'),
        ] as $url) {
            $response->assertSee($url, false);
        }
    }

    public function test_staff_navigation_omits_admin_sections(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::Staff);

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('pos.index'), false)
            ->assertSee(route('products.index'), false)
            ->assertSee(route('inventory.index'), false)
            ->assertSee(route('orders.index'), false)
            ->assertDontSee(route('admin.categories.index'), false)
            ->assertDontSee(route('admin.products.index'), false);
    }
}
