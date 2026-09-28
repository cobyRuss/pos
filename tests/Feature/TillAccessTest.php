<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selling is a cashier responsibility. An administrator who can ring up sales
 * can also edit the products, prices and stock behind them, which makes the
 * cashier attribution on every report meaningless.
 */
class TillAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_cannot_open_the_till(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('pos.index'))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');
    }

    public function test_an_admin_cannot_add_items_to_a_cart(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 20]);

        $this->actingAs($admin)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertForbidden();

        $this->assertSame(20, $product->fresh()->stock, 'A denied cart request must not touch stock.');
    }

    public function test_an_admin_cannot_search_the_catalogue_from_the_till(): void
    {
        $admin = User::factory()->admin()->create();
        Product::factory()->create(['name' => 'Cola Can']);

        $this->actingAs($admin)
            ->getJson(route('pos.search', ['q' => 'Cola']))
            ->assertForbidden();
    }

    public function test_an_admin_cannot_check_out(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 20]);

        // A browser form post is bounced back to the dashboard with a message
        // rather than shown a raw 403 page.
        $this->actingAs($admin)
            ->post(route('pos.checkout'), [
                'payment_method' => PaymentMethod::Cash->value,
                'paid_amount' => 100,
            ])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');

        $this->assertSame(0, Order::count(), 'A denied checkout must not create an order.');
        $this->assertSame(20, $product->fresh()->stock);
    }

    public function test_an_admin_cannot_manipulate_the_cart(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->priced(1, 2)->create();

        $this->actingAs($admin)
            ->patch(route('pos.cart.update', $product), ['quantity' => 5])
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs($admin)
            ->delete(route('pos.cart.update', $product))
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs($admin)
            ->delete(route('pos.cart.clear'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_every_till_endpoint_is_closed_to_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->priced(1, 2)->create();

        $attempts = [
            ['get', route('pos.index')],
            ['get', route('pos.search')],
            ['post', route('pos.cart.store')],
            ['patch', route('pos.cart.update', $product)],
            ['delete', route('pos.cart.update', $product)],
            ['delete', route('pos.cart.clear')],
            ['post', route('pos.checkout')],
        ];

        foreach ($attempts as [$method, $url]) {
            $this->actingAs($admin)
                ->{$method}($url)
                ->assertRedirect(route('admin.dashboard'), "{$method} {$url} must be closed to admins.");
        }

        // The same endpoints answer with a hard 403 for JSON callers, which is
        // what the till's own JavaScript sends.
        foreach ($attempts as [$method, $url]) {
            $this->actingAs($admin)
                ->{$method.'Json'}($url)
                ->assertForbidden("{$method}Json {$url} must be forbidden for admins.");
        }
    }

    public function test_a_denied_sale_is_written_to_the_audit_trail(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('pos.index'))->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLogger::ACCESS_DENIED,
            'user_id' => $admin->id,
        ]);
    }

    public function test_staff_can_still_use_the_till(): void
    {
        $staff = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 20]);

        $this->actingAs($staff)->get(route('pos.index'))->assertOk();

        $this->actingAs($staff)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 2])
            ->assertOk();

        $this->actingAs($staff)
            ->post(route('pos.checkout'), [
                'payment_method' => PaymentMethod::Cash->value,
                'paid_amount' => 100,
            ])
            ->assertRedirect(route('orders.receipt', Order::sole()));

        $this->assertSame(18, $product->fresh()->stock);
    }

    public function test_a_sale_is_attributed_to_the_cashier_not_the_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create(['name' => 'Ana Cashier']);
        $product = Product::factory()->priced(1, 2)->create(['stock' => 20]);

        $this->actingAs($staff)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($staff)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 100,
        ]);

        $order = Order::sole();

        $this->assertSame($staff->id, $order->user_id);
        $this->assertSame('Ana Cashier', $order->cashier_name);
        $this->assertNotSame($admin->id, $order->user_id);
    }

    public function test_the_till_link_is_hidden_from_admins_in_the_navigation(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee(route('pos.index'), false);

        $this->actingAs($staff)
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee(route('pos.index'), false);
    }

    public function test_admins_keep_full_oversight_of_sales(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 20]);

        $this->actingAs($staff)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($staff)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 100,
        ]);

        $order = Order::sole();

        // No selling, but everything about the sale stays visible and printable.
        $this->actingAs($admin)->get(route('orders.index'))->assertOk()->assertSee($order->order_number);
        $this->actingAs($admin)->get(route('orders.show', $order))->assertOk();
        $this->actingAs($admin)->get(route('orders.receipt', $order))->assertOk();
        $this->actingAs($admin)->get(route('admin.reports.sales'))->assertOk();
        $this->actingAs($admin)->get(route('inventory.index'))->assertOk();
    }

    public function test_the_receipt_hides_the_new_sale_button_for_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $product = Product::factory()->priced(1, 2)->create(['stock' => 20]);

        $this->actingAs($staff)
            ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($staff)->post(route('pos.checkout'), [
            'payment_method' => PaymentMethod::Cash->value,
            'paid_amount' => 100,
        ]);

        $order = Order::sole();

        $this->actingAs($admin)
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertDontSee('New Sale');

        $this->actingAs($staff)
            ->get(route('orders.receipt', $order))
            ->assertOk()
            ->assertSee('New Sale');
    }

    public function test_guests_are_still_sent_to_login_from_the_till(): void
    {
        $this->get(route('pos.index'))->assertRedirect(route('login'));
    }

    public function test_a_staff_member_cannot_reach_admin_pages_from_the_till(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('error');
    }
}
