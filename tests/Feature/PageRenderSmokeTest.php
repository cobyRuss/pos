<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every screen touched by the batch and expiry work must render.
 *
 * The feature tests each assert one behaviour, so a page that only breaks on
 * some other role or some other filter can slip through. This walks them all.
 */
class PageRenderSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_screens_render(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 12]);
        $expiring = Product::factory()->create(['stock' => 0, 'name' => 'Tomatoes 1kg']);
        $expiring->batches()->create([
            'batch_no' => 'TOM-1',
            'expiry_date' => now()->addDays(4)->toDateString(),
            'quantity' => 8,
        ]);
        $expiring->forceFill(['stock' => 8])->save();

        $expired = Product::factory()->create(['stock' => 0, 'name' => 'Baby Spinach 200g']);
        $expired->batches()->create([
            'expiry_date' => now()->subDays(2)->toDateString(),
            'quantity' => 3,
        ]);
        $expired->forceFill(['stock' => 3])->save();

        $pages = [
            'dashboard' => route('admin.dashboard'),
            'products' => route('products.index'),
            'product create' => route('admin.products.create'),
            'product edit' => route('admin.products.edit', $product),
            'product show' => route('products.show', $expiring),
            'inventory' => route('inventory.index'),
            'inventory expiring' => route('inventory.index', ['status' => 'expiring']),
            'inventory expired' => route('inventory.index', ['status' => 'expired']),
            'movements' => route('admin.inventory.movements'),
            'adjust' => route('admin.inventory.create', $product),
            'batches' => route('admin.batches.index', $expiring),
            'reports' => route('admin.reports.index'),
            'reports inventory' => route('admin.reports.inventory'),
            'reports inventory expiring' => route('admin.reports.inventory', ['status' => 'expiring']),
            'orders' => route('orders.index'),
            'refunds' => route('admin.refunds.index'),
            'audit' => route('admin.audit-logs.index'),
            'users' => route('admin.users.index'),
            'categories' => route('admin.categories.index'),
        ];

        foreach ($pages as $label => $url) {
            $this->actingAs($admin)
                ->get($url)
                ->assertOk("The {$label} screen failed to render.");
        }
    }

    public function test_a_product_with_several_lots_renders_every_expiry_state(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 0]);

        // One of each: fresh, expiring, expired, and undated.
        $product->batches()->create(['batch_no' => 'FRESH', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 5]);
        $product->batches()->create(['batch_no' => 'SOON', 'expiry_date' => now()->addDays(6)->toDateString(), 'quantity' => 4]);
        $product->batches()->create(['batch_no' => 'GONE', 'expiry_date' => now()->subDays(4)->toDateString(), 'quantity' => 2]);
        $product->batches()->create(['batch_no' => 'GENERAL', 'quantity' => 6]);
        $product->forceFill(['stock' => 17])->save();

        foreach ([
            'product show' => route('products.show', $product),
            'batches' => route('admin.batches.index', $product),
            'adjust' => route('admin.inventory.create', $product),
            'inventory' => route('inventory.index'),
        ] as $label => $url) {
            $this->actingAs($admin)
                ->get($url)
                ->assertOk("The {$label} screen failed with mixed lot states.");
        }
    }

    public function test_staff_screens_render_with_expiry_data(): void
    {
        $staff = User::factory()->staff()->create();
        $product = Product::factory()->create(['stock' => 0]);
        $product->batches()->create([
            'expiry_date' => now()->addDays(2)->toDateString(),
            'quantity' => 6,
        ]);
        $product->forceFill(['stock' => 6])->save();

        foreach ([
            'till' => route('pos.index'),
            'inventory' => route('inventory.index'),
            'products' => route('products.index'),
            'product show' => route('products.show', $product),
            'orders' => route('orders.index'),
        ] as $label => $url) {
            $this->actingAs($staff)
                ->get($url)
                ->assertOk("The staff {$label} screen failed to render.");
        }
    }

    public function test_the_till_warns_about_expiry_without_blocking_the_grid(): void
    {
        $staff = User::factory()->staff()->create();

        $soon = Product::factory()->create(['stock' => 0, 'name' => 'Butter Croissant']);
        $soon->batches()->create(['expiry_date' => now()->addDays(2)->toDateString(), 'quantity' => 5]);
        $soon->forceFill(['stock' => 5])->save();

        $gone = Product::factory()->create(['stock' => 0, 'name' => 'Baby Spinach 200g']);
        $gone->batches()->create(['expiry_date' => now()->subDays(1)->toDateString(), 'quantity' => 4]);
        $gone->forceFill(['stock' => 4])->save();

        // Partly expired: still sellable, but the cashier should be told.
        $mixed = Product::factory()->create(['stock' => 0, 'name' => 'Whole Milk 1L']);
        $mixed->batches()->create(['expiry_date' => now()->subDays(1)->toDateString(), 'quantity' => 3]);
        $mixed->batches()->create(['expiry_date' => now()->addMonth()->toDateString(), 'quantity' => 2]);
        $mixed->forceFill(['stock' => 5])->save();

        // A wholly expired product is refused at the till rather than sold.
        $this->actingAs($staff)
            ->postJson(route('pos.cart.store'), ['product_id' => $gone->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'All 4 unit(s) of "Baby Spinach 200g" are past their expiry date and cannot be sold.']);

        // The rest of the grid is still browsable, and the lapsed item is marked.
        $this->actingAs($staff)
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Baby Spinach 200g')
            ->assertSee('Butter Croissant');

        foreach ([$soon, $mixed] as $product) {
            $this->actingAs($staff)
                ->postJson(route('pos.cart.store'), ['product_id' => $product->id, 'quantity' => 1])
                ->assertOk();
        }

        $this->actingAs($staff)
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Butter Croissant')
            ->assertSee('Whole Milk 1L')
            // The 2-day lot is called out on its own line.
            ->assertSee('Expires in 2 days')
            // And a line that is only partly sellable says so.
            ->assertSee('Some of this stock is past its expiry date');
    }
}
