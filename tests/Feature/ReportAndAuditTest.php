<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportAndAuditTest extends TestCase
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

    private function sale(float $total, float $refunded = 0.0, string $method = 'cash'): Order
    {
        return Order::factory()->create([
            'user_id' => $this->staff->id,
            'subtotal' => $total,
            'total' => $total,
            'refunded_total' => $refunded,
            'payment_method' => $method,
        ]);
    }

    public function test_the_rolling_report_renders_with_totals(): void
    {
        $this->sale(100.00);
        $this->sale(50.00, 20.00);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Net revenue')
            ->assertSee('130.00')
            ->assertSee('Refunded');
    }

    public function test_the_report_range_is_applied_to_the_order_list(): void
    {
        $this->sale(100.00);
        Order::factory()->create([
            'user_id' => $this->staff->id,
            'total' => 999.00,
            'created_at' => now()->subDays(10),
        ]);

        // Today sees one order; a 30-day window sees both.
        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['range' => 'day']))
            ->assertOk()
            ->assertDontSee('999.00');

        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['range' => 'month']))
            ->assertOk()
            ->assertSee('999.00');
    }

    public function test_the_report_can_be_filtered_by_payment_method(): void
    {
        $cash = $this->sale(30.00, 0, 'cash');
        $card = $this->sale(70.00, 0, 'card');

        // Order numbers are asserted rather than amounts because the sidebar
        // breakdown deliberately covers the whole range, not the filter.
        $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['range' => 'day', 'payment_method' => 'card']))
            ->assertOk()
            ->assertSee($card->order_number)
            ->assertDontSee($cash->order_number);
    }

    public function test_the_custom_date_range_report_requires_both_dates(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.sales'))
            ->assertSessionHasErrors(['from', 'to']);
    }

    public function test_the_custom_date_range_report_filters_by_date(): void
    {
        $this->sale(10.00);
        Order::factory()->create([
            'user_id' => $this->staff->id,
            'total' => 500.00,
            'created_at' => now()->subMonth(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.sales', [
                'from' => now()->subDays(2)->toDateString(),
                'to' => now()->addDay()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('10.00')
            ->assertDontSee('500.00');
    }

    public function test_an_inverted_date_range_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.sales', [
                'from' => now()->toDateString(),
                'to' => now()->subWeek()->toDateString(),
            ]))
            ->assertSessionHasErrors('to');
    }

    public function test_orders_can_be_exported_as_csv(): void
    {
        $order = $this->sale(42.00);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.sales', [
                'from' => now()->subDay()->toDateString(),
                'to' => now()->addDay()->toDateString(),
                'format' => 'csv',
            ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertDownload();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Order,Date,Time,Cashier', $csv);
        $this->assertStringContainsString($order->order_number, $csv);
        $this->assertStringContainsString('42.00', $csv);
    }

    public function test_the_csv_export_respects_the_filters(): void
    {
        $this->sale(30.00, 0, 'cash');
        $this->sale(70.00, 0, 'card');

        $csv = $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['range' => 'day', 'payment_method' => 'cash', 'format' => 'csv']))
            ->streamedContent();

        $this->assertStringContainsString('30.00', $csv);
        $this->assertStringNotContainsString('70.00', $csv);
    }

    public function test_the_rolling_report_can_be_exported_as_csv(): void
    {
        $this->sale(15.00);

        $csv = $this->actingAs($this->admin)
            ->get(route('admin.reports.index', ['range' => 'day', 'format' => 'csv']))
            ->streamedContent();

        $this->assertStringContainsString('15.00', $csv);
    }

    public function test_staff_cannot_read_reports(): void
    {
        $this->actingAs($this->staff)
            ->get(route('admin.reports.index'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_guests_cannot_read_reports(): void
    {
        $this->get(route('admin.reports.index'))->assertRedirect(route('login'));
        $this->get(route('admin.reports.sales'))->assertRedirect(route('login'));
    }

    public function test_the_audit_log_lists_recorded_actions(): void
    {
        app(AuditLogger::class)->record(
            AuditLogger::ORDER_CANCELLED,
            $this->sale(10.00),
            $this->admin->id,
            'Cancelled order for a test.',
        );

        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertSee(AuditLogger::ORDER_CANCELLED)
            ->assertSee('Cancelled order for a test.')
            ->assertSee($this->admin->name);
    }

    public function test_the_audit_log_can_be_filtered_by_action_and_user(): void
    {
        app(AuditLogger::class)->record(AuditLogger::ORDER_CANCELLED, null, $this->admin->id, 'cancelled-a-sale');
        app(AuditLogger::class)->record(AuditLogger::SETTINGS_UPDATED, null, $this->staff->id, 'changed-the-tax-rate');

        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index', ['action' => AuditLogger::ORDER_CANCELLED]))
            ->assertOk()
            ->assertSee('cancelled-a-sale')
            ->assertDontSee('changed-the-tax-rate');

        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index', ['user_id' => $this->staff->id]))
            ->assertOk()
            ->assertSee('changed-the-tax-rate')
            ->assertDontSee('cancelled-a-sale');
    }

    public function test_the_audit_log_can_be_searched(): void
    {
        app(AuditLogger::class)->record(AuditLogger::REFUND_PROCESSED, null, $this->admin->id, 'Refund for order POS-ABC');

        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index', ['q' => 'POS-ABC']))
            ->assertOk()
            ->assertSee('Refund for order POS-ABC');
    }

    public function test_staff_cannot_read_the_audit_log(): void
    {
        $this->actingAs($this->staff)
            ->get(route('admin.audit-logs.index'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_guests_cannot_read_the_audit_log(): void
    {
        $this->get(route('admin.audit-logs.index'))->assertRedirect(route('login'));
    }

    public function test_an_audit_entry_shows_the_subject_it_refers_to(): void
    {
        $order = $this->sale(10.00);

        app(AuditLogger::class)->record(AuditLogger::ORDER_CANCELLED, $order, $this->admin->id, 'Voided');

        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertSee('Order')
            ->assertSee('#'.$order->id);
    }

    public function test_the_audit_log_survives_an_empty_table(): void
    {
        $this->assertSame(0, AuditLog::count());

        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertSee('No audit entries match these filters');
    }
}
