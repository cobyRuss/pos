<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected User $otherStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin);

        $this->staff = User::factory()->create();
        $this->staff->assignRole(Role::Staff);

        $this->otherStaff = User::factory()->create();
        $this->otherStaff->assignRole(Role::Staff);
    }

    public function test_staff_only_see_their_own_sales_in_the_list(): void
    {
        $mine = Order::factory()->create(['user_id' => $this->staff->id]);
        $theirs = Order::factory()->create(['user_id' => $this->otherStaff->id]);

        $response = $this->actingAs($this->staff)->get(route('orders.index'));

        $response->assertOk();
        $response->assertSee($mine->order_number);
        $response->assertDontSee($theirs->order_number);
    }

    public function test_admins_see_every_sale(): void
    {
        $mine = Order::factory()->create(['user_id' => $this->staff->id]);
        $theirs = Order::factory()->create(['user_id' => $this->otherStaff->id]);

        $response = $this->actingAs($this->admin)->get(route('orders.index'));

        $response->assertOk();
        $response->assertSee($mine->order_number);
        $response->assertSee($theirs->order_number);
    }

    public function test_staff_cannot_open_another_staff_members_order(): void
    {
        $theirs = Order::factory()->create(['user_id' => $this->otherStaff->id]);

        // orders.view alone must not expose the whole shop's takings. The
        // browser is bounced with a flash, and nothing about the order is
        // rendered in the response.
        $response = $this->actingAs($this->staff)->get(route('orders.show', $theirs));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertDontSee($theirs->order_number);
    }

    public function test_staff_can_open_their_own_order(): void
    {
        $order = Order::factory()->create(['user_id' => $this->staff->id]);

        $this->actingAs($this->staff)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee($order->order_number);
    }

    public function test_an_admin_can_open_any_order(): void
    {
        $order = Order::factory()->create(['user_id' => $this->otherStaff->id]);

        $this->actingAs($this->admin)
            ->get(route('orders.show', $order))
            ->assertOk();
    }

    public function test_staff_cannot_open_another_staff_members_receipt(): void
    {
        $order = Order::factory()->create(['user_id' => $this->otherStaff->id]);

        $response = $this->actingAs($this->staff)->get(route('pos.receipt', $order));

        $response->assertRedirect();
        $response->assertDontSee($order->order_number);
    }

    public function test_the_history_can_be_searched_by_order_number(): void
    {
        $wanted = Order::factory()->create(['user_id' => $this->staff->id]);
        $other = Order::factory()->create(['user_id' => $this->staff->id]);

        $response = $this->actingAs($this->staff)
            ->get(route('orders.index', ['q' => $wanted->order_number]));

        $response->assertOk();
        $response->assertSee($wanted->order_number);
        $response->assertDontSee($other->order_number);
    }

    public function test_the_history_can_be_filtered_by_status(): void
    {
        $cancelled = Order::factory()->cancelled()->create(['user_id' => $this->staff->id]);
        $completed = Order::factory()->create(['user_id' => $this->staff->id]);

        $response = $this->actingAs($this->staff)
            ->get(route('orders.index', ['status' => Order::STATUS_CANCELLED]));

        $response->assertOk();
        $response->assertSee($cancelled->order_number);
        $response->assertDontSee($completed->order_number);
    }

    public function test_an_invalid_filter_is_rejected(): void
    {
        $this->actingAs($this->staff)
            ->get(route('orders.index', ['status' => 'nonsense']))
            ->assertSessionHasErrors('status');
    }

    public function test_the_receipt_prints_the_totals_and_the_cashier(): void
    {
        $order = Order::factory()->create([
            'user_id' => $this->staff->id,
            'total' => 31.50,
            'amount_paid' => 40.00,
            'change_amount' => 8.50,
        ]);

        $this->actingAs($this->staff)
            ->get(route('pos.receipt', $order))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('31.50')
            ->assertSee('8.50')
            ->assertSee($this->staff->name);
    }

    public function test_a_cancelled_order_is_marked_on_the_receipt(): void
    {
        $order = Order::factory()->cancelled()->create(['user_id' => $this->staff->id]);

        $this->actingAs($this->staff)
            ->get(route('pos.receipt', $order))
            ->assertOk()
            ->assertSee('CANCELLED');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $order = Order::factory()->create();

        $this->get(route('orders.index'))->assertRedirect(route('login'));
        $this->get(route('orders.show', $order))->assertRedirect(route('login'));
    }
}
