<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Section 5 requires authorization at both the route and the Blade level. The
 * route half is verified here across the whole surface rather than route by
 * route, so a new admin route added later is covered automatically.
 */
class AuthorizationMatrixTest extends TestCase
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
     * Every route inside the admin prefix must refuse a staff member, whoever
     * is signed in. This is the single most important authorization property in
     * the application: staff run the till, they do not run the shop.
     */
    public function test_staff_are_refused_every_admin_route(): void
    {
        $product = Product::factory()->create();
        $order = Order::factory()->create(['user_id' => $this->staff->id]);

        // Concrete values for every parameter any admin route declares.
        $bindings = [
            'product' => $product->getRouteKey(),
            'category' => $product->category->getRouteKey(),
            'order' => $order->getRouteKey(),
            'user' => $this->staff->getRouteKey(),
            'refund' => $this->admin->id,
        ];

        $checked = 0;
        $leaks = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'admin/')) {
                continue;
            }

            $url = '/'.$uri;

            foreach ($bindings as $parameter => $value) {
                $url = str_replace('{'.$parameter.'}', (string) $value, $url);
            }

            $response = $this->actingAs($this->staff)->get($url);

            if ($response->getStatusCode() === 200) {
                $leaks[] = $uri;
            }

            $checked++;
        }

        $this->assertSame([], $leaks, 'Staff can reach: '.implode(', ', $leaks));
        $this->assertGreaterThan(10, $checked, 'Expected the admin surface to be covered by this test');
    }

    public function test_an_admin_can_reach_every_admin_page(): void
    {
        $product = Product::factory()->create();
        $order = Order::factory()->create(['user_id' => $this->admin->id]);

        $pages = [
            'admin.products.index' => [],
            'admin.products.create' => [],
            'admin.products.edit' => ['product' => $product],
            'admin.categories.index' => [],
            'admin.categories.create' => [],
            'admin.categories.edit' => ['category' => $product->category],
            'inventory.index' => [],
            'inventory.logs' => [],
            'admin.inventory.adjust.create' => ['product' => $product],
            'pos.index' => [],
            'orders.index' => [],
            'orders.show' => ['order' => $order],
            'admin.refunds.index' => [],
            'admin.staff.index' => [],
            'admin.staff.create' => [],
            'admin.staff.edit' => ['user' => $this->staff],
            'admin.reports.index' => [],
            'admin.audit-logs.index' => [],
            'admin.settings.edit' => [],
        ];

        foreach ($pages as $name => $parameters) {
            $this->actingAs($this->admin)
                ->get(route($name, $parameters))
                ->assertOk("Route [{$name}] should be reachable by an admin.");
        }
    }

    public function test_staff_only_hold_the_documented_permissions(): void
    {
        $expected = Permission::forStaff();

        foreach (Permission::cases() as $permission) {
            $shouldHave = in_array($permission, $expected, true);

            $this->assertSame(
                $shouldHave,
                $this->staff->hasPermissionTo($permission->value),
                "Staff permission mismatch for [{$permission->value}].",
            );
        }
    }

    public function test_permission_gated_actions_are_hidden_from_staff_in_the_ui(): void
    {
        // The order screen is the one page both roles see, so it is where a
        // leaked admin-only control would be visible to a cashier.
        $order = Order::factory()->create(['user_id' => $this->staff->id]);

        $this->actingAs($this->staff)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee(route('admin.refunds.store', $order), false)
            ->assertDontSee(route('orders.cancel', $order), false);

        $this->actingAs($this->admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee(route('orders.cancel', $order), false);
    }

    public function test_staff_navigation_hides_every_admin_link(): void
    {
        $this->actingAs($this->staff)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('admin.staff.index'), false)
            ->assertDontSee(route('admin.reports.index'), false)
            ->assertDontSee(route('admin.audit-logs.index'), false)
            ->assertDontSee(route('admin.settings.edit'), false)
            ->assertDontSee(route('admin.refunds.index'), false);
    }
}
