<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\RefundReason;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Support\AuditLogger;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['name' => 'Ada Admin']);
    }

    /**
     * Factories stamp orders with the current time, so "now" based reports are
     * queried for today unless a test pins its own dates.
     *
     * @return array<string, string>
     */
    private function today(): array
    {
        $today = Carbon::today()->toDateString();

        return ['from' => $today, 'to' => $today];
    }

    public function test_the_dashboard_summarises_the_shop(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->priced(1, 4)->create(['stock' => 20, 'low_stock_threshold' => 5]);
        $low = Product::factory()->lowStock(1)->create();
        $out = Product::factory()->outOfStock()->create();

        Order::factory()->create(['total' => 40, 'user_id' => $admin->id]);
        Order::factory()->cancelled()->create(['total' => 99, 'user_id' => $admin->id]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk()
            ->assertSee('Ada Admin')
            ->assertSee($low->name)
            ->assertSee($out->name);

        $view = $response->viewData();
        $this->assertSame(1, $view['orderCount'], 'Cancelled orders must not count towards sales.');
        $this->assertEquals(40.0, (float) $view['totalSales']);
        $this->assertSame(2, $view['lowStockCount'], 'lowStock() is "at or below threshold", so the empty item counts too.');
        $this->assertSame(1, $view['outOfStockCount']);
        $this->assertSame(2, $view['lowStockProducts']->count(), 'Out-of-stock products also sit at or below their threshold.');
    }

    public function test_the_dashboard_is_admin_only(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get(route('admin.dashboard'))->assertRedirect(route('pos.index'));
    }

    public function test_the_sales_report_respects_the_date_range(): void
    {
        $admin = $this->admin();

        Order::factory()->at(Carbon::parse('2026-03-10'))->create(['total' => 100]);
        Order::factory()->at(Carbon::parse('2026-03-20'))->create(['total' => 50]);
        Order::factory()->at(Carbon::parse('2026-01-05'))->create(['total' => 999]);

        $view = $this->actingAs($admin)
            ->get(route('admin.reports.sales', ['from' => '2026-03-01', 'to' => '2026-03-31']))
            ->assertOk()
            ->viewData();

        $this->assertSame(2, $view['totals']['orders']);
        $this->assertEquals(150.0, $view['totals']['gross']);
    }

    public function test_the_sales_report_excludes_cancelled_orders_from_totals(): void
    {
        $admin = $this->admin();

        Order::factory()->create(['total' => 100]);
        Order::factory()->cancelled()->create(['total' => 100]);

        $view = $this->actingAs($admin)
            ->get(route('admin.reports.sales', $this->today()))
            ->viewData();

        $this->assertSame(1, $view['totals']['orders']);
        $this->assertEquals(100.0, $view['totals']['gross']);
    }

    public function test_the_sales_report_can_be_filtered_by_status_and_method(): void
    {
        $admin = $this->admin();

        Order::factory()->create(['total' => 100, 'payment_method' => PaymentMethod::Cash]);
        Order::factory()->create(['total' => 70, 'payment_method' => PaymentMethod::Gcash]);
        Order::factory()->cancelled()->create(['total' => 30, 'payment_method' => PaymentMethod::Gcash]);

        $cancelled = $this->actingAs($admin)
            ->get(route('admin.reports.sales', [...$this->today(), 'status' => 'cancelled']))
            ->viewData();

        $this->assertSame(1, $cancelled['totals']['orders']);
        $this->assertEquals(30.0, $cancelled['totals']['gross']);

        $card = $this->actingAs($admin)
            ->get(route('admin.reports.sales', [...$this->today(), 'payment_method' => 'gcash']))
            ->viewData();

        $this->assertSame(1, $card['totals']['orders']);
        $this->assertEquals(70.0, $card['totals']['gross']);

        $this->actingAs($admin)
            ->get(route('admin.reports.sales', ['status' => 'nonsense']))
            ->assertSessionHasErrors('status');
    }

    public function test_the_sales_report_nets_off_refunds(): void
    {
        $admin = $this->admin();
        $order = Order::factory()->create(['total' => 100, 'refunded_amount' => 25]);

        $view = $this->actingAs($admin)
            ->get(route('admin.reports.sales', $this->today()))
            ->viewData();

        $this->assertEquals(25.0, $view['totals']['refunded']);
        $this->assertEquals(75.0, $view['totals']['net']);
        $this->assertEquals(100.0, $view['totals']['average']);

        $this->assertSame($order->order_number, Order::first()->order_number);
    }

    public function test_the_sales_report_can_be_exported_as_csv(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->priced(2.00, 5.00)->create(['stock' => 20]);
        $this->sellThroughPos($product, 2);

        $response = $this->actingAs($admin)
            ->get(route('admin.reports.sales', [...$this->today(), 'export' => 1]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Order #', $csv);
        $this->assertStringContainsString('Buying cost', $csv);
        $this->assertStringContainsString('Profit', $csv);

        $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));
        $header = array_shift($rows);
        $data = $rows[0];

        $this->assertSame('4.00', $data[array_search('Buying cost', $header, true)], '2 units at 2.00 buying cost.');
        $this->assertSame('6.00', $data[array_search('Profit', $header, true)], '10.00 revenue - 4.00 cost.');
    }

    public function test_the_sales_report_shows_buying_price_selling_price_and_profit(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->priced(2.00, 5.00)->create(['name' => 'Margin Item']);

        $this->sellThroughPos($product, 4);

        $view = $this->actingAs($admin)
            ->get(route('admin.reports.sales', $this->today()))
            ->assertOk()
            ->assertSee('Margin Item')
            ->assertSee('Gross profit')
            ->assertSee('Buying cost')
            ->viewData();

        // 4 units at 5.00 selling / 2.00 buying.
        $this->assertEquals(20.00, $view['profit']['revenue']);
        $this->assertEquals(8.00, $view['profit']['cost']);
        $this->assertEquals(12.00, $view['profit']['profit']);
        $this->assertEquals(60.0, $view['profit']['margin']);
        $this->assertSame(4, $view['profit']['units']);

        $top = $view['topProducts']->first();
        $this->assertSame('Margin Item', $top->name);
        $this->assertEquals(2.00, (float) $top->buying_price);
        $this->assertEquals(5.00, (float) $top->selling_price);
        $this->assertEquals(20.00, (float) $top->revenue);
        $this->assertEquals(12.00, (float) $top->profit);
    }

    public function test_the_buying_price_is_frozen_at_the_time_of_sale(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->priced(2.00, 5.00)->create(['name' => 'Repriced Item']);

        $this->sellThroughPos($product, 2);

        // The shop later negotiates a better price from its supplier.
        $product->forceFill(['cost_price' => 1.00])->save();

        $item = OrderItem::sole();
        $this->assertEquals(2.00, (float) $item->unit_cost, 'Historic profit must not change when a cost is edited.');

        $view = $this->actingAs($admin)
            ->get(route('admin.reports.sales', $this->today()))
            ->viewData();

        $this->assertEquals(4.00, $view['profit']['cost']);
        $this->assertEquals(6.00, $view['profit']['profit']);
        $this->assertEquals(2.00, (float) $view['topProducts']->first()->buying_price);
    }

    public function test_refunded_units_leave_the_profit_calculation(): void
    {
        $admin = $this->admin();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(2.00, 5.00)->create(['name' => 'Partly Returned', 'stock' => 20]);

        Setting::setMany(['tax_rate' => '0', 'currency_symbol' => '$']);
        Setting::flushCache();

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 4]);
        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 100,
        ]);

        $order = Order::sole();
        $line = $order->items->sole();

        $this->actingAs($admin)->post(route('refunds.store', $order), [
            'reason_code' => RefundReason::Damaged->value,
            'method' => PaymentMethod::Cash->value,
            'items' => [$line->id => 2],
        ]);

        $view = $this->actingAs($admin)
            ->get(route('admin.reports.sales', $this->today()))
            ->viewData();

        // 2 units left sold: 10.00 revenue, 4.00 cost.
        $this->assertSame(2, $view['profit']['units']);
        $this->assertEquals(10.00, $view['profit']['revenue']);
        $this->assertEquals(4.00, $view['profit']['cost']);
        $this->assertEquals(6.00, $view['profit']['profit']);
        $this->assertEquals(4.00, $view['profit']['refunded_cost']);
    }

    public function test_profit_excludes_tax_collected(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->priced(1.00, 2.00)->create(['name' => 'Taxed Item', 'stock' => 20]);

        $this->sellThroughPos($product, 5, taxRate: '20');

        $order = Order::sole();
        $this->assertEquals(2.00, (float) $order->tax_amount, 'Sanity check: tax was charged.');

        $view = $this->actingAs($admin)
            ->get(route('admin.reports.sales', $this->today()))
            ->viewData();

        $this->assertEquals(10.00, $view['profit']['revenue'], 'Tax is a liability, not revenue.');
        $this->assertEquals(5.00, $view['profit']['cost']);
        $this->assertEquals(5.00, $view['profit']['profit']);
    }

    public function test_a_sale_below_cost_reports_a_loss(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->priced(4.00, 3.00)->create(['name' => 'Clearance Item', 'stock' => 20]);

        $this->sellThroughPos($product, 2);

        $view = $this->actingAs($admin)
            ->get(route('admin.reports.sales', $this->today()))
            ->viewData();

        $this->assertEquals(6.00, $view['profit']['revenue']);
        $this->assertEquals(8.00, $view['profit']['cost']);
        $this->assertEquals(-2.00, $view['profit']['profit']);
        $this->assertEquals(-33.3, $view['profit']['margin']);
    }

    /**
     * Rings up a real sale through the till so cost capture is exercised.
     */
    private function sellThroughPos(Product $product, int $quantity, string $taxRate = '0'): Order
    {
        Setting::setMany(['tax_rate' => $taxRate, 'currency_symbol' => '$']);
        Setting::flushCache();

        $cashier = User::factory()->staff()->create();

        $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => $quantity])
            ->assertOk();

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 10000,
        ])->assertRedirect();

        return Order::with('items')->latest()->first();
    }

    public function test_the_sales_report_lists_the_best_selling_lines(): void
    {
        $admin = $this->admin();
        $cola = Product::factory()->priced(1, 2)->create(['name' => 'Cola Can']);
        $crisps = Product::factory()->priced(1, 3)->create(['name' => 'Salted Crisps']);

        $order = Order::factory()->create(['total' => 5.00]);
        $order->items()->create([
            'product_id' => $cola->id,
            'product_name' => $cola->name,
            'quantity' => 4,
            'unit_price' => 1.00,
            'discount_amount' => 0,
            'line_total' => 4.00,
        ]);
        $order->items()->create([
            'product_id' => $crisps->id,
            'product_name' => $crisps->name,
            'quantity' => 2,
            'unit_price' => 1.50,
            'discount_amount' => 0,
            'line_total' => 3.00,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.reports.sales', $this->today()));

        $response->assertOk()
            ->assertSee('Cola Can')
            ->assertSee('Salted Crisps');

        $top = $response->viewData('topProducts');

        $this->assertCount(2, $top);
        $this->assertSame('Cola Can', $top->first()->name, 'product_name must be aliased to name for the view.');
        $this->assertSame(4, (int) $top->first()->units);
        $this->assertEquals(4.00, (float) $top->first()->revenue);
        $this->assertSame($cola->id, $top->first()->product_id);
    }

    public function test_the_revenue_report_compares_against_the_previous_period(): void
    {
        $admin = $this->admin();

        Order::factory()->at(Carbon::parse('2026-03-05'))->create(['total' => 200]);
        Order::factory()->at(Carbon::parse('2026-02-05'))->create(['total' => 100]);

        $view = $this->actingAs($admin)
            ->get(route('admin.reports.revenue', ['from' => '2026-03-01', 'to' => '2026-03-31']))
            ->assertOk()
            ->viewData();

        $this->assertEquals(200.0, $view['totals']['gross']);
        $this->assertEquals(200.0, $view['comparison']['current']);
        $this->assertEquals(100.0, $view['comparison']['previous']);
        $this->assertEquals(100.0, $view['comparison']['change']);
    }

    public function test_the_inventory_report_groups_by_category(): void
    {
        $admin = $this->admin();
        $drinks = Category::factory()->create(['name' => 'Drinks']);
        $drink = Product::factory()->priced(1, 2)->create(['category_id' => $drinks->id, 'stock' => 10]);
        $low = Product::factory()->lowStock(2)->create(['category_id' => $drinks->id]);
        $out = Product::factory()->outOfStock()->create(['category_id' => $drinks->id]);

        $response = $this->actingAs($admin)->get(route('admin.reports.inventory'));
        $response->assertOk()->assertSee('Drinks')->assertSee($drink->name);

        $summary = $response->viewData()['summary'];
        $this->assertSame(3, (int) $summary['products']);
        $this->assertSame(12, (int) $summary['units']);
        // lowStock() means "at or below threshold", so the empty product is in both counts.
        $this->assertSame(2, (int) $summary['low']);
        $this->assertSame(1, (int) $summary['out']);

        $lowView = $this->actingAs($admin)->get(route('admin.reports.inventory', ['status' => 'low']));
        $lowView->assertOk()->assertSee($low->name);

        $outView = $this->actingAs($admin)->get(route('admin.reports.inventory', ['status' => 'out']));
        $outView->assertOk()->assertSee($out->name)->assertDontSee($low->name);

        $okView = $this->actingAs($admin)->get(route('admin.reports.inventory', ['status' => 'ok']));
        $okView->assertOk()->assertSee($drink->name)->assertDontSee($low->name);

        $this->actingAs($admin)->get(route('admin.reports.inventory', ['status' => 'weird']))->assertSessionHasErrors('status');
    }

    public function test_the_inventory_report_can_be_exported(): void
    {
        $admin = $this->admin();
        Product::factory()->create(['name' => 'Exportable Item']);

        $response = $this->actingAs($admin)->get(route('admin.reports.inventory', ['export' => 1]));

        $response->assertOk();
        $this->assertStringContainsString('Exportable Item', $response->streamedContent());
    }

    public function test_reports_are_admin_only(): void
    {
        $staff = User::factory()->staff()->create();

        foreach (['admin.reports.index', 'admin.reports.sales', 'admin.reports.revenue', 'admin.reports.inventory'] as $name) {
            $this->actingAs($staff)->get(route($name))->assertRedirect(route('pos.index'));
        }
    }

    public function test_the_dashboard_tracks_profit_for_the_period(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->priced(2.00, 5.00)->create(['name' => 'Dashboard Item', 'stock' => 50]);

        $this->sellThroughPos($product, 4, taxRate: '0');

        $view = $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Profit Tracking')
            ->assertSee('Total Cost')
            ->assertSee('Total Sales')
            ->assertSee('Total Profit')
            ->assertSee('Profit Margin')
            ->viewData();

        $sold = $view['profit']['sold'];

        $this->assertEquals(8.00, $sold['cost'], '4 units at 2.00 buying cost.');
        $this->assertEquals(20.00, $sold['sales']);
        $this->assertEquals(12.00, $sold['profit']);
        $this->assertEquals(60.0, $sold['margin']);
        $this->assertSame(4, $sold['quantity']);
        $this->assertSame(0, $sold['losses']);

        $line = $sold['lines']->first();
        $this->assertSame('Dashboard Item', $line->name);
        $this->assertEquals(2.00, (float) $line->cost_price);
        $this->assertEquals(5.00, (float) $line->selling_price);
        $this->assertSame(4, $line->quantity);
        $this->assertEquals(12.00, (float) $line->profit);
    }

    public function test_the_dashboard_reports_profit_on_current_stock(): void
    {
        $admin = $this->admin();
        $cola = Product::factory()->priced(1.00, 2.00)->create(['name' => 'Stock Item', 'stock' => 10]);
        Product::factory()->priced(5.00, 1.00)->create(['name' => 'Bad Price Item', 'stock' => 20]);
        Product::factory()->outOfStock()->create(['name' => 'Nothing Left']);

        $stock = $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('current stock')
            ->viewData('profit')['stock'];

        // 10 x (2.00 - 1.00) = +10 profit, 20 x (1.00 - 5.00) = -80 loss.
        $this->assertEquals(10.00 + 100.00, $stock['cost']);
        $this->assertEquals(20.00 + 20.00, $stock['sales']);
        $this->assertEquals(-70.00, $stock['profit'], 'A loss-making line drags the total negative.');
        $this->assertEquals(-175.0, $stock['margin']);
        $this->assertSame(30, $stock['quantity']);
        $this->assertSame(2, $stock['products'], 'Out of stock products are excluded.');
        $this->assertSame(1, $stock['losses']);

        $bad = $stock['lines']->firstWhere('name', 'Bad Price Item');
        $this->assertEquals(-80.00, (float) $bad->profit);
        $this->assertEquals(5.00, (float) $bad->cost_price);
        $this->assertEquals(1.00, (float) $bad->selling_price);

        $good = $stock['lines']->firstWhere('name', 'Stock Item');
        $this->assertEquals(10.00, (float) $good->profit);
    }

    public function test_dashboard_profit_totals_follow_price_and_quantity_changes(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->priced(2.00, 5.00)->create(['name' => 'Live Item', 'stock' => 10]);

        $first = $this->actingAs($admin)->get(route('admin.dashboard'))->viewData('profit')['stock'];
        $this->assertEquals(30.00, $first['profit']);

        // Change the selling price and the quantity: no extra saving required.
        $product->forceFill(['selling_price' => 8.00, 'stock' => 20])->save();

        $second = $this->actingAs($admin)->get(route('admin.dashboard'))->viewData('profit')['stock'];
        $this->assertEquals(120.00, $second['profit'], '(8.00 - 2.00) x 20');
        $this->assertEquals(160.00, $second['sales']);

        // Push the price under cost: the total flips negative and is kept negative.
        $product->forceFill(['selling_price' => 1.00])->save();

        $third = $this->actingAs($admin)->get(route('admin.dashboard'))->viewData('profit')['stock'];
        $this->assertEquals(-20.00, $third['profit']);
        $this->assertSame(1, $third['losses']);
        $this->assertLessThan(0, $third['margin']);
    }

    public function test_the_dashboard_shows_a_loss_badge_for_negative_profit(): void
    {
        $admin = $this->admin();
        Product::factory()->priced(4.00, 3.00)->create(['name' => 'Clearance Widget', 'stock' => 5]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Clearance Widget')
            ->assertSee('Loss')
            ->assertSee('priced below cost');
    }

    public function test_dashboard_profit_ignores_cancelled_orders(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->priced(1.00, 4.00)->create(['name' => 'Cancelled Item', 'stock' => 30]);

        $this->sellThroughPos($product, 5, taxRate: '0');

        $before = $this->actingAs($admin)->get(route('admin.dashboard'))->viewData('profit')['sold'];
        $this->assertEquals(15.00, $before['profit']);

        Order::sole()->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

        $after = $this->actingAs($admin)->get(route('admin.dashboard'))->viewData('profit')['sold'];
        $this->assertEquals(0.0, $after['profit']);
        $this->assertSame(0, $after['products']);
    }

    public function test_dashboard_profit_nets_off_refunded_units(): void
    {
        $admin = $this->admin();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(2.00, 5.00)->create(['name' => 'Refunded Item', 'stock' => 20]);

        Setting::setMany(['tax_rate' => '0', 'currency_symbol' => '₱']);
        Setting::flushCache();

        $this->actingAs($cashier)->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 4]);
        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 100,
        ]);

        $order = Order::sole();
        $line = $order->items->sole();

        $this->actingAs($admin)->post(route('refunds.store', $order), [
            'reason_code' => RefundReason::Damaged->value,
            'method' => PaymentMethod::Cash->value,
            'items' => [$line->id => 2],
        ]);

        $sold = $this->actingAs($admin)->get(route('admin.dashboard'))->viewData('profit')['sold'];

        $this->assertSame(2, $sold['quantity'], 'Refunded units are no longer sold.');
        $this->assertEquals(10.00, $sold['sales']);
        $this->assertEquals(4.00, $sold['cost']);
        $this->assertEquals(6.00, $sold['profit']);
    }

    public function test_the_default_currency_is_the_peso_sign(): void
    {
        // No settings row exists yet, so the model fallback is what renders.
        $this->assertSame('₱', Setting::currency());
        $this->assertSame('₱1,234.50', Setting::money(1234.5));

        // And a freshly seeded store defaults to the same symbol.
        $this->seed(SettingSeeder::class);

        $this->assertSame('₱', Setting::currency());
        $this->assertSame('₱99.00', Setting::money(99));
    }

    public function test_settings_are_saved_and_audited(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), [
                'store_name' => 'Corner Shop',
                'store_address' => '12 High Street',
                'store_phone' => '555-0100',
                'store_email' => 'hello@cornershop.test',
                'currency_symbol' => '£',
                'tax_rate' => 7.5,
                'receipt_footer' => 'See you soon!',
                'receipt_size' => '58mm',
                'receipt_prefix' => '88',
                'scpwd_discount_rate' => 20,
                'store_tin' => '123-456-789',
                'store_vat_status' => 'vat',
                'bir_permit_no' => 'PTCA-99887',
                'bir_accreditation_no' => 'ACC-12345',
                'bir_machine_no' => 'MCH-0001',
                'low_stock_default' => 9,
                'refund_review_threshold' => 750,
                'refund_daily_limit' => 2000,
                'telegram_bot_token' => '123456789:AAExampleTokenValue',
                'telegram_chat_id' => '-1001234567890',
            ])
            ->assertRedirect(route('admin.settings.edit'));

        $this->assertSame('Corner Shop', Setting::get('store_name'));
        $this->assertSame(7.5, (float) Setting::get('tax_rate'));
        $this->assertSame('£', Setting::currency());
        $this->assertEquals(750.0, Setting::refundReviewThreshold());
        $this->assertEquals(2000.0, Setting::refundDailyLimit());
        $this->assertSame('-1001234567890', Setting::telegramChatId());
        $this->assertSame('123-456-789', Setting::get('store_tin'));
        $this->assertSame('PTCA-99887', Setting::get('bir_permit_no'));
        $this->assertSame('MCH-0001', Setting::get('bir_machine_no'));
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::SETTINGS_UPDATED]);
    }

    public function test_settings_are_validated(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), [
                'tax_rate' => -1,
                'receipt_size' => 'A4-ish',
                'low_stock_default' => -5,
                'store_email' => 'not-an-email',
                'refund_daily_limit' => -1,
                'telegram_chat_id' => 'not-a-chat-id',
            ])
            ->assertSessionHasErrors(['tax_rate', 'receipt_size', 'low_stock_default', 'store_email', 'refund_daily_limit', 'telegram_chat_id']);
    }

    public function test_settings_are_admin_only(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get(route('admin.settings.edit'))->assertRedirect(route('pos.index'));
        $this->actingAs($staff)->put(route('admin.settings.update'), [])->assertRedirect(route('pos.index'));
    }

    public function test_a_changed_tax_rate_applies_to_new_orders(): void
    {
        $admin = $this->admin();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 10)->create(['stock' => 50]);

        Setting::setMany(['tax_rate' => '10', 'currency_symbol' => '$']);
        Setting::flushCache();

        $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 2])
            ->assertOk();

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 100,
        ]);

        $order = Order::sole();

        $this->assertEquals(20.00, (float) $order->subtotal);
        $this->assertEquals(2.00, (float) $order->tax_amount);
        $this->assertEquals(22.00, (float) $order->total);
    }

    public function test_audit_logs_are_recorded_and_filterable(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create();

        $this->actingAs($admin)->delete(route('admin.products.destroy', $product));

        $response = $this->actingAs($admin)->get(route('admin.audit-logs.index'));
        $response->assertOk()->assertSee(AuditLogger::PRODUCT_DELETED, false);
        $this->assertSame(1, $response->viewData('logs')->total());

        // The action dropdown always lists every known action, so count the rows.
        $logins = $this->actingAs($admin)->get(route('admin.audit-logs.index', ['action' => AuditLogger::LOGIN]));
        $logins->assertOk();
        $this->assertSame(0, $logins->viewData('logs')->total());

        $deletes = $this->actingAs($admin)->get(route('admin.audit-logs.index', ['action' => AuditLogger::PRODUCT_DELETED]));
        $deletes->assertOk();
        $this->assertSame(1, $deletes->viewData('logs')->total());

        $staff = User::factory()->staff()->create();
        $this->actingAs($staff)->get(route('admin.audit-logs.index'))->assertRedirect(route('pos.index'));
    }

    public function test_refund_records_are_visible_on_the_order(): void
    {
        $admin = $this->admin();
        $order = Order::factory()->create(['total' => 50]);
        $refund = Refund::create([
            'refund_number' => 'REF-TEST-1',
            'order_id' => $order->id,
            'user_id' => $admin->id,
            'status' => 'completed',
            'amount' => 12.50,
            'method' => PaymentMethod::Cash,
            'reason' => 'Broken on arrival',
            'refunded_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('REF-TEST-1');

        $this->actingAs($admin)
            ->get(route('refunds.show', $refund))
            ->assertOk()
            ->assertSee('Broken on arrival');
    }
}
