<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use ReflectionClass;
use Tests\TestCase;

class PaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_paginator_uses_bootstrap_markup_not_tailwind(): void
    {
        // Laravel's default view is Tailwind, which renders as unstyled text in
        // this Bootstrap 5 app. AppServiceProvider points it at Bootstrap.
        // The setters have no getters, so read the static state directly.
        $defaults = (new ReflectionClass(Paginator::class))->getStaticPropertyValue('defaultView');
        $simpleDefaults = (new ReflectionClass(Paginator::class))->getStaticPropertyValue('defaultSimpleView');

        $this->assertSame('pagination::bootstrap-5', $defaults);
        $this->assertSame('pagination::simple-bootstrap-5', $simpleDefaults);
    }

    public function test_a_multi_page_listing_renders_working_bootstrap_controls(): void
    {
        $admin = User::factory()->admin()->create();
        Product::factory()->count(20)->create();

        $response = $this->actingAs($admin)->get(route('products.index'));

        $response->assertOk()
            ->assertSee('page-link', false)
            ->assertSee('Showing', false)
            // Numbered links with an active state.
            ->assertSee('aria-current="page"', false)
            ->assertSee('page-item active', false)
            // Tailwind classes from Laravel's default paginator must be gone.
            ->assertDontSee('inline-flex items-center', false)
            ->assertDontSee('rounded-md', false);
    }

    public function test_pagination_links_carry_the_active_filters(): void
    {
        $admin = User::factory()->admin()->create();
        Product::factory()->count(20)->create(['name' => 'Cola Special']);

        $this->actingAs($admin)
            ->get(route('products.index', ['q' => 'Cola', 'status' => 'active']))
            ->assertOk()
            ->assertSee('q=Cola', false)
            ->assertSee('status=active', false)
            ->assertSee('page=2', false);
    }

    public function test_paging_forward_and_back_shows_different_rows(): void
    {
        $admin = User::factory()->admin()->create();
        Product::factory()->count(20)->create();

        $this->actingAs($admin)
            ->get(route('products.index', ['sort' => 'name', 'direction' => 'asc']))
            ->assertOk();

        $firstPage = Product::orderBy('name')->first();

        $this->actingAs($admin)
            ->get(route('products.index', ['page' => 2, 'sort' => 'name', 'direction' => 'asc']))
            ->assertOk();

        $secondPageProducts = Product::orderBy('name')->skip(15)->take(15)->pluck('id');

        $response = $this->actingAs($admin)
            ->get(route('products.index', ['page' => 2, 'sort' => 'name', 'direction' => 'asc']));

        $response->assertOk();
        $this->assertNotContains($firstPage->id, $secondPageProducts->all());
        $response->assertSee('Showing', false);
    }

    public function test_a_single_page_listing_shows_no_pagination(): void
    {
        $admin = User::factory()->admin()->create();
        Product::factory()->count(3)->create();

        $this->actingAs($admin)
            ->get(route('products.index'))
            ->assertOk()
            ->assertDontSee('page-link', false);
    }

    public function test_order_history_paginates_and_keeps_its_filters(): void
    {
        $admin = User::factory()->admin()->create();
        Order::factory()->count(18)->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('orders.index', ['status' => 'completed']))
            ->assertOk()
            ->assertSee('page-link', false)
            ->assertSee('Sales (18)', false)
            ->assertSee('status=completed', false);
    }

    public function test_an_out_of_range_page_still_renders(): void
    {
        $admin = User::factory()->admin()->create();
        Product::factory()->count(20)->create();

        $this->actingAs($admin)
            ->get(route('products.index', ['page' => 99]))
            ->assertOk()
            ->assertSee('page-link', false);
    }

    public function test_other_listings_paginate_too(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();

        // Enough rows for every listing below to overflow its per-page size
        // (products page at 15, the inventory report at 20).
        User::factory()->count(20)->staff()->create();
        Product::factory()->count(24)->create();
        Order::factory()->count(25)->create(['user_id' => $staff->id]);

        // Factories do not write audit rows, so seed a log per order directly.
        foreach (Order::where('user_id', $staff->id)->get() as $order) {
            AuditLog::create([
                'user_id' => $admin->id,
                'user_name' => $admin->name,
                'action' => AuditLogger::ORDER_CREATED,
                'description' => 'Pagination fixture for order '.$order->id,
            ]);
        }

        foreach (Order::where('user_id', $staff->id)->take(16)->get() as $order) {
            Refund::create([
                'refund_number' => 'REF-PAG-'.$order->id,
                'order_id' => $order->id,
                'user_id' => $admin->id,
                'status' => 'completed',
                'amount' => 5.00,
                'method' => 'cash',
                'reason' => 'Pagination fixture',
                'refunded_at' => now(),
            ]);
        }

        foreach ([
            'inventory' => route('inventory.index'),
            'orders' => route('orders.index'),
            'audit logs' => route('admin.audit-logs.index'),
            'users' => route('admin.users.index'),
            'refunds' => route('admin.refunds.index'),
            'sales report' => route('admin.reports.sales', ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()]),
            'inventory report' => route('admin.reports.inventory'),
        ] as $label => $url) {
            $this->actingAs($admin)
                ->get($url)
                ->assertOk()
                ->assertSee('page-link', false, "The {$label} listing should paginate with Bootstrap controls.");
        }
    }
}
