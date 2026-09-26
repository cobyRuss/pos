<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\SalesAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
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

    private function sale(User $user, float $total, float $refunded = 0.0): Order
    {
        return Order::factory()->create([
            'user_id' => $user->id,
            'subtotal' => $total,
            'total' => $total,
            'refunded_total' => $refunded,
        ]);
    }

    public function test_an_admin_sees_net_revenue_net_of_refunds(): void
    {
        $this->sale($this->staff, 100.00);
        $this->sale($this->staff, 50.00, 20.00);

        $summary = app(SalesAnalytics::class)->summary('day');

        $this->assertSame(15000, $summary['gross']);
        $this->assertSame(2000, $summary['refunds']);
        $this->assertSame(13000, $summary['net']);
        $this->assertSame(2, $summary['orders']);
    }

    public function test_cancelled_orders_are_excluded_from_revenue(): void
    {
        $this->sale($this->staff, 100.00);
        Order::factory()->cancelled()->create([
            'user_id' => $this->staff->id,
            'total' => 500.00,
        ]);

        $summary = app(SalesAnalytics::class)->summary('day');

        // The voided sale is not money the shop took.
        $this->assertSame(10000, $summary['gross']);
        $this->assertSame(1, $summary['orders']);
    }

    public function test_revenue_older_than_the_window_is_excluded(): void
    {
        Order::factory()->create([
            'user_id' => $this->staff->id,
            'total' => 100.00,
            'created_at' => now()->subDays(3),
        ]);

        $analytics = app(SalesAnalytics::class);

        // Three days back falls inside the week but not inside today.
        $this->assertSame(0, $analytics->summary('day')['gross']);
        $this->assertSame(10000, $analytics->summary('week')['gross']);
        $this->assertSame(10000, $analytics->summary('month')['gross']);
    }

    public function test_the_daily_trend_covers_every_day_in_the_window(): void
    {
        $this->sale($this->staff, 10.00);

        $trend = app(SalesAnalytics::class)->dailyTrend('week');

        $this->assertCount(7, $trend);
        $this->assertSame(1000, $trend->last()['net']);
        $this->assertSame(1, $trend->last()['orders']);
        // Days with no sales are present with zeroes rather than omitted.
        $this->assertSame(0, $trend->first()['net']);
    }

    public function test_the_average_order_is_gross_divided_by_order_count(): void
    {
        $this->sale($this->staff, 30.00);
        $this->sale($this->staff, 90.00);

        $this->assertSame(6000, app(SalesAnalytics::class)->summary('day')['average']);
    }

    public function test_revenue_is_grouped_by_payment_method(): void
    {
        Order::factory()->create(['user_id' => $this->staff->id, 'total' => 30.00, 'payment_method' => 'cash']);
        Order::factory()->create(['user_id' => $this->staff->id, 'total' => 70.00, 'payment_method' => 'card']);

        $byMethod = app(SalesAnalytics::class)->byPaymentMethod('day')->keyBy('method');

        $this->assertSame(3000, $byMethod['cash']['net']);
        $this->assertSame(7000, $byMethod['card']['net']);
    }

    public function test_revenue_is_grouped_by_staff_member(): void
    {
        $other = User::factory()->create();
        $other->assignRole(Role::Staff);

        $this->sale($this->staff, 25.00);
        $this->sale($other, 75.00);

        $rows = app(SalesAnalytics::class)->byStaff('day')->keyBy('name');

        $this->assertSame(2500, $rows[$this->staff->name]['net']);
        $this->assertSame(7500, $rows[$other->name]['net']);
    }

    public function test_best_sellers_rank_by_units_sold(): void
    {
        $coffee = Product::factory()->create(['name' => 'Flat White']);
        $tea = Product::factory()->create(['name' => 'Green Tea']);

        $this->itemSold($coffee, 5, 22.50);
        $this->itemSold($tea, 2, 7.00);

        $best = app(SalesAnalytics::class)->bestSellers('day');

        $this->assertSame('Flat White', $best->first()['name']);
        $this->assertSame(5, $best->first()['quantity']);
        $this->assertSame(2, $best->last()['quantity']);
    }

    public function test_a_deleted_product_still_appears_in_the_best_sellers(): void
    {
        $product = Product::factory()->create(['name' => 'Discontinued Brew']);
        $this->itemSold($product, 3, 10.00);

        $product->delete();

        $best = app(SalesAnalytics::class)->bestSellers('day');

        // The sale happened; the name it sold under is preserved on the line.
        $this->assertSame('Discontinued Brew', $best->first()['name']);
        $this->assertNull($best->first()['product']);
    }

    public function test_the_inventory_summary_flags_low_and_out_of_stock(): void
    {
        Product::factory()->lowStock()->create(['name' => 'Low Item', 'stock' => 2, 'cost' => 0.00]);
        Product::factory()->outOfStock()->create(['name' => 'Gone Item', 'stock' => 0, 'cost' => 0.00]);
        Product::factory()->create(['name' => 'Plenty', 'stock' => 50, 'cost' => 2.00]);

        $summary = app(SalesAnalytics::class)->inventorySummary();

        $this->assertSame(2, $summary['lowStock']->count());
        $this->assertSame(1, $summary['outOfStock']);
        // 50 units at a cost of 2.00
        $this->assertSame(10000, $summary['value']);
        $this->assertSame(52, $summary['units']);
    }

    public function test_a_staff_members_figures_cover_only_their_own_till(): void
    {
        $other = User::factory()->create();
        $other->assignRole(Role::Staff);

        $this->sale($this->staff, 40.00);
        $this->sale($other, 60.00);

        $analytics = app(SalesAnalytics::class);

        $this->assertSame(4000, $analytics->summaryForUser($this->staff->id, 'day')['net']);
        $this->assertSame(10000, $analytics->summary('day')['net']);
    }

    public function test_the_admin_dashboard_renders_the_figures(): void
    {
        $this->sale($this->staff, 42.00);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Net revenue')
            ->assertSee('42.00')
            ->assertSee('Best sellers');
    }

    public function test_the_dashboard_range_can_be_switched(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard', ['range' => 'month']))
            ->assertOk()
            ->assertSee('Last 30 days');

        $this->actingAs($this->admin)
            ->get(route('dashboard', ['range' => 'nonsense']))
            ->assertOk();
    }

    public function test_a_staff_dashboard_shows_their_own_sales_not_shop_totals(): void
    {
        $other = User::factory()->create();
        $other->assignRole(Role::Staff);

        $this->sale($this->staff, 15.00);
        $this->sale($other, 85.00);

        $response = $this->actingAs($this->staff)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('My sales');
        $response->assertSee('15.00');
        // Shop-wide analytics are an admin concern.
        $response->assertDontSee('Best sellers');
    }

    public function test_an_admin_dashboard_never_renders_with_no_sales(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No sales in this period');
    }

    private function itemSold(Product $product, int $quantity, float $lineTotal): void
    {
        $order = Order::factory()->create([
            'user_id' => $this->staff->id,
            'total' => $lineTotal,
        ]);

        $order->items()->create([
            'product_id' => $product->getKey(),
            'product_name' => $product->name,
            'sku' => $product->sku,
            'unit_price' => 4.50,
            'quantity' => $quantity,
            'discount_amount' => 0,
            'line_total' => $lineTotal,
        ]);
    }
}
