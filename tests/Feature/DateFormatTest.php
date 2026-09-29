<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Support\DateFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The store trades 24/7, so a timestamp has to read the same at 21:40 as it does
 * at 03:40, and a date has to be unambiguous for a cashier reading a note off a
 * receipt. These lock both down.
 *
 * The formats used to be string literals scattered across forty templates that
 * had drifted into three conventions with 12-hour times mixed in. The helper
 * centralises them; these tests are what stop them drifting again.
 */
class DateFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_dates_are_mm_dd_yyyy(): void
    {
        $this->assertSame('03/14/2026', DateFormat::date('2026-03-14'));
        $this->assertSame('12/31/2026', DateFormat::date('2026-12-31'));

        // The ambiguity this rules out: 03/04 is 4 March in most of the world
        // and 3 April here. The store's own format has to be the one that is
        // never ambiguous for its own staff.
        $this->assertStringStartsWith('03/', DateFormat::date('2026-03-04'));
    }

    public function test_times_are_24_hour(): void
    {
        // The whole reason: 19:26 must not render as "7:26 PM" next to a
        // morning sale, where nobody can tell at a glance which is which.
        $this->assertSame('19:26', DateFormat::time('2026-03-14 19:26:45'));
        $this->assertSame('07:26', DateFormat::time('2026-03-14 07:26:45'));
        $this->assertSame('00:05', DateFormat::time('2026-03-14 00:05:00'));
        $this->assertSame('23:59', DateFormat::time('2026-12-31 23:59:00'));
    }

    public function test_date_and_time_combine_in_the_house_style(): void
    {
        $this->assertSame('03/14/2026 19:26', DateFormat::dateTime('2026-03-14 19:26:45'));
        $this->assertSame('03/14/2026 19:26:45', DateFormat::dateTimeSeconds('2026-03-14 19:26:45'));
        $this->assertSame('03/14', DateFormat::dayShort('2026-03-14'));
    }

    public function test_nothing_renders_as_a_twelve_hour_clock(): void
    {
        // Every one of these used to print an AM/PM marker somewhere on screen.
        foreach (['00:30', '09:05', '12:00', '13:47', '19:26', '23:59'] as $time) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(AM|PM)\b/i',
                DateFormat::time("2026-03-14 {$time}:00"),
            );
        }
    }

    public function test_a_missing_timestamp_renders_as_a_dash(): void
    {
        // A blank cell in a table of timestamps reads as "not recorded" when it
        // means "has not happened yet".
        $this->assertSame('—', DateFormat::date(null));
        $this->assertSame('—', DateFormat::time(null));
        $this->assertSame('—', DateFormat::dateTime(null));
        $this->assertSame('—', DateFormat::dateTimeSeconds(null));
    }

    public function test_the_receipt_prints_the_house_style(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 5)->create();

        $order = app(\App\Services\OrderService::class)->checkout(
            ['items' => [$product->id => ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5]]],
            ['paid_amount' => 5, 'payment_method' => 'cash'],
            $cashier,
        );

        $stamp = Carbon::parse('2026-03-14 19:26:45');
        $order->forceFill(['created_at' => $stamp, 'updated_at' => $stamp])->save();

        $this->actingAs($cashier)
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee('03/14/2026')
            ->assertSee('19:26')
            ->assertDontSee('7:26 PM')
            ->assertDontSee('Mar 2026');
    }

    public function test_the_orders_list_prints_the_house_style(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 5)->create();

        $order = app(\App\Services\OrderService::class)->checkout(
            ['items' => [$product->id => ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5]]],
            ['paid_amount' => 5, 'payment_method' => 'cash'],
            $cashier,
        );

        $stamp = Carbon::parse('2026-07-19 23:41:00');
        $order->forceFill(['created_at' => $stamp, 'updated_at' => $stamp])->save();

        $this->actingAs($cashier)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertSee('07/19/2026')
            ->assertSee('23:41')
            ->assertDontSee('11:41 PM');
    }

    public function test_the_drawer_shift_times_are_24_hour(): void
    {
        $cashier = User::factory()->staff()->create();

        $open = app(\App\Services\DrawerService::class)->open($cashier, 500.00);

        // A shift opened at 09:00. The badge used to read "Opened 9:00 AM",
        // which is exactly the ambiguity a store that never closes cannot
        // afford - at 21:00 it is not obvious whether the drawer is from this
        // morning or this evening.
        $open->forceFill(['opened_at' => Carbon::parse('2026-07-19 09:00:00')])->save();

        $this->actingAs($cashier)
            ->get(route('drawer.index'))
            ->assertOk()
            ->assertSee('09:00')
            ->assertDontSee('9:00 AM');
    }

    public function test_the_counted_drawer_history_prints_the_house_style(): void
    {
        $cashier = User::factory()->staff()->create();

        $session = app(\App\Services\DrawerService::class)->open($cashier, 500.00);
        $session->forceFill([
            'opened_at' => Carbon::parse('2026-07-19 09:00:00'),
            'closed_at' => Carbon::parse('2026-07-19 23:41:00'),
            'counted_cash' => 500.00,
        ])->save();

        $this->actingAs($cashier)
            ->get(route('drawer.index'))
            ->assertOk()
            ->assertSee('07/19/2026 23:41')
            ->assertDontSee('11:41 PM')
            ->assertDontSee('Jul 19, 2026');
    }
}
