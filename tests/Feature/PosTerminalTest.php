<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosTerminalTest extends TestCase
{
    use RefreshDatabase;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create();
        $this->staff->assignRole(Role::Staff);
    }

    public function test_the_terminal_renders_with_products(): void
    {
        Product::factory()->create(['name' => 'Flat White', 'price' => 4.50, 'stock' => 10]);
        Product::factory()->inactive()->create(['name' => 'Discontinued Brew']);

        $this->actingAs($this->staff)
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('POS Terminal')
            ->assertSee('Flat White')
            // Archived products are not sellable, so they are not offered.
            ->assertDontSee('Discontinued Brew');
    }

    public function test_the_terminal_can_be_searched_and_filtered(): void
    {
        $coffee = Category::factory()->create(['name' => 'Coffee']);
        $tea = Category::factory()->create(['name' => 'Tea']);

        Product::factory()->create(['name' => 'Flat White', 'category_id' => $coffee->id]);
        Product::factory()->create(['name' => 'Green Tea', 'category_id' => $tea->id]);

        $this->actingAs($this->staff)
            ->get(route('pos.index', ['q' => 'Flat White']))
            ->assertOk()
            ->assertSee('Flat White')
            ->assertDontSee('Green Tea');

        $this->actingAs($this->staff)
            ->get(route('pos.index', ['category' => $tea->id]))
            ->assertOk()
            ->assertSee('Green Tea')
            ->assertDontSee('Flat White');
    }

    public function test_a_product_can_be_added_to_the_cart(): void
    {
        $product = Product::factory()->create(['price' => 3.00, 'stock' => 10]);

        $this->actingAs($this->staff)
            ->from(route('pos.index'))
            ->post(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 2])
            ->assertRedirect(route('pos.index'))
            ->assertSessionHas('success');

        $this->actingAs($this->staff)
            ->get(route('pos.index'))
            ->assertSee($product->name);
    }

    public function test_the_terminal_renders_the_basket_when_it_is_not_empty(): void
    {
        $product = Product::factory()->create(['name' => 'Flat White', 'price' => 4.50, 'stock' => 10]);

        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 2]);

        // The basket panel, the discount form and the payment form are only
        // rendered once something is in the cart, so an empty-cart render
        // cannot prove those branches work.
        $this->actingAs($this->staff)
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Flat White')
            ->assertSee('9.00')
            ->assertSee('Complete Sale');
    }

    public function test_the_terminal_renders_each_discount_type(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 10]);

        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id]);

        foreach ([Cart::DISCOUNT_NONE, Cart::DISCOUNT_FIXED, Cart::DISCOUNT_PERCENT] as $type) {
            $this->actingAs($this->staff)
                ->from(route('pos.index'))
                ->post(route('pos.cart.discount'), [
                    'discount_type' => $type,
                    'discount_value' => $type === Cart::DISCOUNT_NONE ? 0 : 1,
                ])
                ->assertRedirect(route('pos.index'));

            $this->actingAs($this->staff)
                ->get(route('pos.index'))
                ->assertOk()
                ->assertSee('Current Sale');
        }
    }

    public function test_an_out_of_stock_product_cannot_be_added(): void
    {
        $product = Product::factory()->outOfStock()->create();

        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id])
            ->assertSessionHasErrors('product_id');

        $this->assertSame(0, app(Cart::class)->count());
    }

    public function test_an_archived_product_cannot_be_added(): void
    {
        $product = Product::factory()->inactive()->create();

        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id])
            ->assertSessionHasErrors('product_id');
    }

    public function test_more_than_the_available_stock_cannot_be_added(): void
    {
        $product = Product::factory()->create(['stock' => 2]);

        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 5])
            ->assertSessionHasErrors('quantity');
    }

    public function test_adding_up_to_the_available_stock_is_allowed(): void
    {
        $product = Product::factory()->create(['stock' => 2]);

        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 2])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, app(Cart::class)->quantityOf($product->id));
    }

    public function test_adding_past_the_stock_on_a_second_add_is_rejected(): void
    {
        $product = Product::factory()->create(['stock' => 3]);

        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 2])
            ->assertSessionHasNoErrors();

        // Two in the cart, one left: the cashier is told the real remainder.
        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 2])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(2, app(Cart::class)->quantityOf($product->id));
    }

    public function test_a_line_quantity_can_be_changed_and_removed(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($this->staff)
            ->patch(route('pos.cart.update', $product), [
                'product_id' => $product->id,
                'quantity' => 5,
            ])
            ->assertSessionHas('success');

        $this->assertSame(5, app(Cart::class)->quantityOf($product->id));

        $this->actingAs($this->staff)
            ->delete(route('pos.cart.destroy', $product))
            ->assertSessionHas('success');

        $this->assertTrue(app(Cart::class)->isEmpty());
    }

    public function test_a_quantity_beyond_stock_cannot_be_set(): void
    {
        $product = Product::factory()->create(['stock' => 4]);

        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id]);

        $this->actingAs($this->staff)
            ->patch(route('pos.cart.update', $product), [
                'product_id' => $product->id,
                'quantity' => 99,
            ])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(1, app(Cart::class)->quantityOf($product->id));
    }

    public function test_the_cart_can_be_cleared(): void
    {
        $product = Product::factory()->create(['stock' => 5]);

        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id]);

        $this->actingAs($this->staff)
            ->delete(route('pos.cart.clear'))
            ->assertSessionHas('success');

        $this->assertTrue(app(Cart::class)->isEmpty());
    }

    public function test_a_cart_discount_can_be_applied_and_cleared(): void
    {
        $product = Product::factory()->create(['price' => 10.00, 'stock' => 5]);

        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($this->staff)
            ->post(route('pos.cart.discount'), [
                'discount_type' => Cart::DISCOUNT_PERCENT,
                'discount_value' => 10,
            ])
            ->assertSessionHas('success');

        $this->assertSame(200, app(Cart::class)->discountAmount());

        $this->actingAs($this->staff)
            ->post(route('pos.cart.discount'), [
                'discount_type' => Cart::DISCOUNT_NONE,
                'discount_value' => 0,
            ]);

        $this->assertSame(0, app(Cart::class)->discountAmount());
    }

    public function test_a_discount_above_one_hundred_percent_is_rejected(): void
    {
        $product = Product::factory()->create(['stock' => 5]);
        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id]);

        $this->actingAs($this->staff)
            ->post(route('pos.cart.discount'), [
                'discount_type' => Cart::DISCOUNT_PERCENT,
                'discount_value' => 150,
            ])
            ->assertSessionHasErrors('discount_value');
    }

    public function test_a_fixed_discount_above_the_cart_total_is_rejected(): void
    {
        $product = Product::factory()->create(['price' => 5.00, 'stock' => 5]);
        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id]);

        $this->actingAs($this->staff)
            ->post(route('pos.cart.discount'), [
                'discount_type' => Cart::DISCOUNT_FIXED,
                'discount_value' => 500,
            ])
            ->assertSessionHasErrors('discount_value');
    }

    public function test_a_zero_discount_value_is_rejected(): void
    {
        $product = Product::factory()->create(['stock' => 5]);
        $this->actingAs($this->staff)
            ->post(route('pos.cart.store'), ['product_id' => $product->id]);

        $this->actingAs($this->staff)
            ->post(route('pos.cart.discount'), [
                'discount_type' => Cart::DISCOUNT_FIXED,
                'discount_value' => 0,
            ])
            ->assertSessionHasErrors('discount_value');
    }

    public function test_a_user_without_pos_access_cannot_reach_the_terminal(): void
    {
        // An account with no role holds no permissions at all. Permissions
        // granted through the staff role cannot be revoked per-user, so the
        // absence of a role is what "cannot sell" looks like.
        $restricted = User::factory()->create();
        $this->assertFalse($restricted->can(Permission::PosAccess->value));

        $this->actingAs($restricted)
            ->get(route('pos.index'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_guests_cannot_reach_the_terminal(): void
    {
        $this->get(route('pos.index'))->assertRedirect(route('login'));
    }
}
