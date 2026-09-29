<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\RefundReason;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function sell(User $cashier, Product $product, int $quantity = 2, string $taxRate = '10'): Order
    {
        Setting::setMany(['tax_rate' => $taxRate, 'currency_symbol' => '₱']);
        Setting::flushCache();

        $this->actingAs($cashier)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => $quantity])
            ->assertOk();

        $this->actingAs($cashier)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 10000,
        ])->assertRedirect();

        return Order::with('items')->latest()->first();
    }

    public function test_the_receipt_shows_the_date_and_the_time_separately(): void
    {
        $cashier = User::factory()->staff()->create(['name' => 'Ana Cashier']);
        $product = Product::factory()->priced(1, 5)->create(['name' => 'Cola Can']);

        $order = $this->sell($cashier, $product);

        $createdAt = Carbon::parse('2026-03-14 19:26:45');
        $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $response = $this->actingAs($cashier)->get(route('orders.receipt', $order));

        $response->assertOk()
            ->assertSee('Date', false)
            ->assertSee('Time', false)
            // mm/dd/yyyy on the date row, 24-hour clock on the time row. 19:26 is
            // the interesting case: a 12-hour format would print 7:26 PM here.
            ->assertSee('03/14/2026')
            ->assertSee('19:26')
            ->assertDontSee('7:26 PM');
    }

    public function test_the_time_row_tracks_the_order_timestamp(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 5)->create();

        $order = $this->sell($cashier, $product);

        // 23:59 is the case that proves the clock: a 12-hour format prints
        // 11:59 PM and the day boundary is a real risk when nobody is on site.
        foreach (['2026-01-02 08:05:00', '2026-07-19 13:47:00', '2026-12-31 23:59:00'] as $stamp) {
            $order->forceFill(['created_at' => $stamp, 'updated_at' => $stamp])->save();

            $expected = Carbon::parse($stamp);

            $this->actingAs($cashier)
                ->get(route('orders.receipt', $order))
                ->assertOk()
                ->assertSee($expected->format('m/d/Y'))
                ->assertSee($expected->format('H:i'));
        }
    }

    public function test_timestamps_are_rendered_in_the_store_timezone(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 5)->create();

        $order = $this->sell($cashier, $product);

        // The configured zone must be a real PHP timezone, not a raw offset.
        $this->assertNotEmpty(config('app.timezone'));
        $this->assertContains(config('app.timezone'), timezone_identifiers_list(), 'APP_TIMEZONE must be a valid timezone name.');

        // A fresh sale is stamped and printed in the same wall-clock zone.
        $rendered = Carbon::createFromTimestamp($order->fresh()->created_at->getTimestamp())
            ->setTimezone(config('app.timezone'))
            ->format('H:i');

        $this->actingAs($cashier)
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee($rendered);
    }

    public function test_a_sale_prints_the_wall_clock_time_not_utc(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 5)->create();
        $order = $this->sell($cashier, $product);

        $original = config('app.timezone');

        try {
            // Same stored wall-clock string, two zones: the printed time must
            // follow the configured zone rather than being pinned to UTC.
            config(['app.timezone' => 'Asia/Manila']);
            $manila = $this->actingAs($cashier)->get(route('orders.receipt', $order))->getContent();

            config(['app.timezone' => 'UTC']);
            $utc = $this->actingAs($cashier)->get(route('orders.receipt', $order))->getContent();
        } finally {
            config(['app.timezone' => $original]);
        }

        preg_match('#<span>Time</span>\s*<span>([^<]*)</span>#', $manila, $manilaTime);
        preg_match('#<span>Time</span>\s*<span>([^<]*)</span>#', $utc, $utcTime);

        $stored = $order->fresh()->created_at->format('Y-m-d H:i:s');

        // MySQL DATETIME has no zone, so the value is read as wall-clock time in
        // whichever zone the app is configured for - that is the whole point.
        $this->assertSame(
            Carbon::parse($stored, 'Asia/Manila')->format('H:i'),
            trim($manilaTime[1] ?? ''),
        );
        $this->assertSame(
            Carbon::parse($stored, 'UTC')->format('H:i'),
            trim($utcTime[1] ?? ''),
        );
    }

    public function test_the_receipt_carries_the_store_and_sale_details(): void
    {
        Setting::setMany([
            'store_name' => '88 Minimart',
            'store_address' => 'Zone 6, Bangued',
            'store_phone' => '+1 555 0100',
            'receipt_footer' => 'Salamat po!',
            'currency_symbol' => '₱',
            'tax_rate' => '10',
        ]);
        Setting::flushCache();

        $cashier = User::factory()->staff()->create(['name' => 'Ana Cashier']);
        $product = Product::factory()->priced(1, 5)->create(['name' => 'Cola Can']);
        $order = $this->sell($cashier, $product, quantity: 2);

        $this->actingAs($cashier)
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee('88 Minimart')
            ->assertSee('Zone 6, Bangued')
            ->assertSee('+1 555 0100')
            ->assertSee($order->order_number)
            ->assertSee('Ana Cashier')
            ->assertSee(PaymentMethod::Cash->label())
            ->assertSee('Cola Can')
            ->assertSee('Salamat po!')
            ->assertSee(Setting::money($order->total))
            ->assertSee(Setting::money($order->tax_amount))
            ->assertSee('Tax (10%)');
    }

    public function test_a_cancelled_receipt_shows_when_it_was_cancelled(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 5)->create();

        $order = $this->sell($cashier, $product);

        $cancelledAt = Carbon::parse('2026-05-05 11:15:00');
        $order->forceFill([
            'status' => 'cancelled',
            'cancelled_at' => $cancelledAt,
            'cancel_reason' => 'Customer changed mind',
        ])->save();

        $this->actingAs($admin)
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee('CANCELLED')
            ->assertSee('Customer changed mind')
            ->assertSee('05/05/2026')
            ->assertSee('11:15');
    }

    public function test_a_refunded_receipt_shows_the_refund_and_the_net_total(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 5)->create(['stock' => 20]);

        $order = $this->sell($cashier, $product, quantity: 4, taxRate: '0');
        $line = $order->items->sole();

        $this->actingAs($admin)->post(route('refunds.store', $order), [
            'reason_code' => RefundReason::Damaged->value,
            'method' => PaymentMethod::Cash->value,
            'items' => [$line->id => 2],
        ]);

        $order->refresh();

        $this->actingAs($admin)
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee('Refunded')
            ->assertSee('NET')
            ->assertSee('(2 refunded)')
            ->assertSee(Setting::money($order->net_total));
    }

    public function test_staff_cannot_open_another_cashiers_receipt(): void
    {
        $alice = User::factory()->staff()->create();
        $bruno = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 5)->create();

        $order = $this->sell($bruno, $product);

        $this->actingAs($alice)
            ->get(route('orders.receipt', $order))
            ->assertForbidden();

        $this->actingAs($bruno)
            ->get(route('orders.receipt', $order))
            ->assertOk();
    }

    public function test_the_print_stylesheet_does_not_clip_the_receipt(): void
    {
        $css = file_get_contents(public_path('css/app.css'));

        // A fixed paper width inside a printable area narrower than the sheet
        // pushed the right-hand amounts off the page.
        $this->assertStringNotContainsString('width: 58mm', $css);
        $this->assertStringContainsString('width: 100% !important', $css);
        $this->assertMatchesRegularExpression('/@page\s*\{\s*margin:\s*[0-9]+mm/', $css);
        $this->assertStringNotContainsString('margin: 6mm', $css);
    }

    public function test_long_product_names_cannot_push_the_amount_off_the_sheet(): void
    {
        $cashier = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 5)->create([
            'name' => 'Extra Long Promotional Product Name That Will Not Fit On One Line',
        ]);

        $order = $this->sell($cashier, $product);

        $this->actingAs($cashier)
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee('receipt-item-name', false)
            ->assertSee('receipt-line', false);
    }
}
