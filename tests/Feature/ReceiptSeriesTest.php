<?php

namespace Tests\Feature;

use App\Models\DocumentSequence;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A BIR-registered machine prints receipts in a run, not at random. These tests
 * pin that down, because the failure is silent and expensive: a gap or a repeat
 * in the series is exactly what an inspection or a customer's "I have receipt
 * number..." question is answered with.
 */
class ReceiptSeriesTest extends TestCase
{
    use RefreshDatabase;

    private ?User $cashier = null;

    /**
     * One cashier per test. Orders are scoped to the user who rang them up, so
     * a helper that minted a fresh user per sale would hand the receipt screen
     * an order belonging to somebody else.
     */
    private function staff(): User
    {
        return $this->cashier ??= User::factory()->staff()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sell(array $overrides = []): Order
    {
        $product = Product::factory()->priced(10, 20)->create(['stock' => 5]);

        $order = app(OrderService::class)->checkout(
            ['items' => [$product->id => ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20]]],
            array_merge([
                'paid_amount' => 20,
                'payment_method' => 'cash',
            ], $overrides),
            $this->staff(),
        );

        return $order;
    }

    public function test_receipt_numbers_run_consecutively(): void
    {
        $first = $this->sell();
        $second = $this->sell();
        $third = $this->sell();

        $this->assertSame('88-000001', $first->order_number);
        $this->assertSame('88-000002', $second->order_number);
        $this->assertSame('88-000003', $third->order_number);
    }

    public function test_a_failed_sale_does_not_burn_a_receipt_number(): void
    {
        $this->sell();

        // A sale that cannot complete must not consume a number, because no
        // receipt was printed for it. A gap in the series is a question an
        // inspector will ask about; a reused number that was never issued is
        // not.
        $empty = Product::factory()->create(['stock' => 0, 'selling_price' => 10, 'cost_price' => 5]);

        try {
            app(OrderService::class)->checkout(
                ['items' => [$empty->id => ['product_id' => $empty->id, 'quantity' => 1, 'unit_price' => 10]]],
                ['paid_amount' => 10, 'payment_method' => 'cash'],
                $this->staff(),
            );
            $this->fail('Checkout should have refused to sell out-of-stock goods.');
        } catch (\Throwable) {
            // Expected.
        }

        $next = $this->sell();

        $this->assertSame('88-000002', $next->order_number);
    }

    public function test_the_prefix_is_configurable(): void
    {
        Setting::setMany(['receipt_prefix' => 'MINI']);
        Setting::flushCache();

        $this->assertSame('MINI-000001', $this->sell()->order_number);
    }

    public function test_the_counter_reports_the_next_unused_number(): void
    {
        $this->assertSame(1, DocumentSequence::nextFor('receipt'));

        $this->sell();
        $this->sell();

        // This is the number the Z-report reconciles against: everything below
        // it was issued, and it has not.
        $this->assertSame(3, DocumentSequence::nextFor('receipt'));
    }

    public function test_the_counter_rolls_over_by_day(): void
    {
        $this->sell();
        $this->assertSame(2, DocumentSequence::nextFor('receipt'));

        $this->travelTo(now()->addDay());
        $this->assertSame(1, DocumentSequence::nextFor('receipt'));
    }

    public function test_the_receipt_prints_the_bir_header(): void
    {
        Setting::setMany([
            'store_name' => '88minimart',
            'store_address' => 'National Highway, Brgy. Malaya',
            'store_tin' => '123-456-789',
            'store_vat_status' => 'vat',
            'bir_permit_no' => 'PTCA-99887',
            'bir_accreditation_no' => 'ACC-12345',
            'bir_machine_no' => 'MCH-0001',
        ]);
        Setting::flushCache();

        $order = $this->sell();

        $this->actingAs($this->staff())
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee('Official Receipt')
            ->assertSee('88minimart')
            ->assertSee('National Highway, Brgy. Malaya')
            ->assertSee('123-456-789')
            ->assertSee('VAT REG')
            ->assertSee('PTCA-99887')
            ->assertSee('ACC-12345')
            ->assertSee('MCH-0001')
            ->assertSee($order->order_number);
    }

    public function test_a_store_with_no_bir_details_still_gets_a_clean_receipt(): void
    {
        Setting::setMany(['store_name' => 'Corner Store', 'store_tin' => '', 'bir_permit_no' => '']);
        Setting::flushCache();

        $order = $this->sell();

        $this->actingAs($this->staff())
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee('Official Receipt')
            ->assertSee('Corner Store')
            // A store that has not been issued a permit yet must not print a
            // row of "TIN: -" across its receipts.
            ->assertDontSee('PTCA/DTI Permit No.')
            ->assertDontSee('Machine Serial No.');
    }

    public function test_a_non_vat_store_prints_its_status_instead_of_vat_registered(): void
    {
        Setting::setMany(['store_tin' => '987-654-321', 'store_vat_status' => 'nonvat']);
        Setting::flushCache();

        $order = $this->sell();

        $this->actingAs($this->staff())
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee('NON-VAT')
            ->assertDontSee('VAT REG');
    }

    public function test_a_bad_tin_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), [
                'store_name' => 'Test',
                'currency_symbol' => '₱',
                'tax_rate' => 0,
                'receipt_size' => '80mm',
                'receipt_prefix' => '88',
                'scpwd_discount_rate' => 20,
                'store_vat_status' => 'vat',
                'low_stock_default' => 5,
                'refund_review_threshold' => 500,
                'refund_daily_limit' => 0,
                'store_tin' => 'not a tin',
            ])
            ->assertSessionHasErrors('store_tin');
    }

    public function test_two_sales_in_the_same_transaction_never_share_a_number(): void
    {
        $product = Product::factory()->priced(10, 20)->create(['stock' => 5]);
        $service = app(OrderService::class);
        $cashier = $this->staff();
        $cart = ['items' => [$product->id => ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20]]];
        $payment = ['paid_amount' => 20, 'payment_method' => 'cash'];

        $numbers = DB::transaction(function () use ($service, $cart, $payment, $cashier) {
            $a = $service->checkout($cart, $payment, $cashier);
            $b = $service->checkout($cart, $payment, $cashier);

            return [$a->order_number, $b->order_number];
        });

        $this->assertSame('88-000001', $numbers[0]);
        $this->assertSame('88-000002', $numbers[1]);
        $this->assertCount(2, Order::pluck('order_number')->unique());
    }
}
