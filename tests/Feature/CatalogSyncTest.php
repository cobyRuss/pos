<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

/**
 * The store's product listing is the record of what it sells. These tests make
 * sure the database agrees with it, because a catalogue that drifts from the
 * listing is how a store ends up selling something it does not stock.
 */
class CatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{description: string, products: array<int, array<string, mixed>>}>
     */
    private function listing(): array
    {
        return (new ReflectionClass(CatalogSeeder::class))->getConstant('CATALOG');
    }

    public function test_the_seeder_creates_every_product_on_the_listing(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        foreach ($this->listing() as $categoryName => $definition) {
            $category = Category::where('name', $categoryName)->sole();

            foreach ($definition['products'] as $row) {
                $product = Product::where('name', $row['name'])->sole();

                $this->assertSame($category->id, $product->category_id, $row['name'].' is in the wrong category.');
                $this->assertEquals($row['cost'], (float) $product->cost_price, $row['name'].' cost does not match the listing.');
                $this->assertEquals($row['price'], (float) $product->selling_price, $row['name'].' selling price does not match the listing.');
            }
        }

        $this->assertSame(49, Product::count());
        $this->assertSame(6, Category::count());
    }

    public function test_prices_from_the_listing_are_used_verbatim(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        // A spot check on real figures from the store's document, including the
        // odd peso-and-sensibles amounts, which is where a rounding slip would
        // hide.
        $this->assertEquals(199.00, (float) Product::where('name', 'Purefoods Luncheon Meat')->sole()->cost_price);
        $this->assertEquals(215.00, (float) Product::where('name', 'Purefoods Luncheon Meat')->sole()->selling_price);
        $this->assertEquals(7.60, (float) Product::where('name', 'Surf Detergent Powder')->sole()->cost_price);
        $this->assertEquals(7.50, (float) Product::where('name', 'Chippy Barbecue')->sole()->cost_price);
        $this->assertEquals(11.00, (float) Product::where('name', 'Chippy Barbecue')->sole()->selling_price);
        $this->assertEquals(20.00, (float) Product::where("name", "Young's Town Sardines")->sole()->cost_price);
    }

    public function test_every_product_sells_above_its_cost(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        // A store does not list a product it expects to lose money on, and the
        // dashboard flags anything sold below cost in red.
        foreach (Product::all() as $product) {
            $this->assertGreaterThan(
                (float) $product->cost_price,
                (float) $product->selling_price,
                $product->name.' is priced at or below cost.',
            );
        }
    }

    public function test_barcodes_are_valid_ean13_and_unique(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        $seen = [];

        foreach (Product::whereNotNull('barcode')->get() as $product) {
            $code = $product->barcode;

            $this->assertSame(13, strlen($code), $product->name.' barcode is not 13 digits.');
            $this->assertTrue(ctype_digit($code), $product->name.' barcode is not numeric.');
            $this->assertStringStartsWith('480', $code, $product->name.' is not on the Philippine GS1 prefix.');
            $this->assertArrayNotHasKey($code, $seen, 'Barcode '.$code.' is used twice.');
            $seen[$code] = true;

            // The real check digit, so a scanner app on a phone accepts it.
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += ((int) $code[$i]) * ($i % 2 === 0 ? 1 : 3);
            }

            $this->assertSame(
                (10 - ($sum % 10)) % 10,
                (int) $code[12],
                $product->name.' has an invalid EAN-13 check digit.',
            );
        }
    }

    public function test_generic_lines_carry_no_barcode(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        // Unbranded lines have no printed code, and the till must cope: they are
        // found by name, which is the same path loose produce takes.
        $this->assertNull(Product::where('name', 'Dishwashing Liquid')->sole()->barcode);
        $this->assertNull(Product::where('name', 'Fabric Conditioner')->sole()->barcode);
        $this->assertSame(47, Product::whereNotNull('barcode')->count());
    }

    public function test_seeding_twice_does_not_duplicate_or_reshuffle(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);
        $before = Product::pluck('barcode', 'name')->all();

        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);
        $after = Product::pluck('barcode', 'name')->all();

        $this->assertSame(49, Product::count());
        $this->assertSame($before, $after, 'Re-seeding must not change a product barcode.');
    }

    public function test_sync_reports_a_product_that_is_not_on_the_listing(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);
        Product::factory()->create(['name' => 'Cola 500ml Can']);

        Artisan::call('catalog:sync');

        // Artisan::output() drains the buffer it reads from, so it has to be
        // captured once and asserted against, not called twice.
        $output = Artisan::output();

        $this->assertStringContainsString('Cola 500ml Can', $output);
        $this->assertStringContainsString('Nothing was changed', $output);

        // Without --prune it is a report, not a delete.
        $this->assertSame(50, Product::count());
    }

    public function test_sync_prunes_products_and_categories_that_are_off_the_listing(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        Product::factory()->create(['name' => 'Cola 500ml Can']);
        $category = Category::factory()->create(['name' => 'Beverages']);

        Artisan::call('catalog:sync', ['--prune' => true]);

        $this->assertSame(49, Product::count());
        $this->assertNull(Product::where('name', 'Cola 500ml Can')->first());
        $this->assertNull(Category::where('key', $category->key)->first());
        $this->assertSame(6, Category::where('is_active', true)->count());
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);
        Product::factory()->create(['name' => 'Cola 500ml Can']);

        Artisan::call('catalog:sync', ['--prune' => true, '--dry-run' => true]);

        $this->assertSame(50, Product::count());
        $this->assertNotNull(Product::where('name', 'Cola 500ml Can')->first());
    }

    public function test_pruning_keeps_a_historic_order_readable(): void
    {
        $cashier = \App\Models\User::factory()->staff()->create();
        $doomed = Product::factory()->priced(5, 10)->create(['name' => 'Cola 500ml Can', 'stock' => 20]);

        $order = app(\App\Services\OrderService::class)->checkout(
            ['items' => [$doomed->id => ['product_id' => $doomed->id, 'quantity' => 2, 'unit_price' => 10]]],
            ['paid_amount' => 20, 'payment_method' => 'cash'],
            $cashier,
        );

        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);
        Artisan::call('catalog:sync', ['--prune' => true]);

        $this->assertNull($doomed->fresh(), 'The product should be gone from the catalogue.');

        // The sale itself must still be readable, because product_name and
        // unit_cost are frozen on the line at the moment of sale. This is the
        // property that makes pruning safe.
        $item = $order->refresh()->items->sole();

        $this->assertNull($item->product_id);
        $this->assertSame('Cola 500ml Can', $item->product_name);
        $this->assertEquals(5.00, (float) $item->unit_cost);

        $this->actingAs($cashier)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Cola 500ml Can');
    }

    public function test_the_till_lists_the_store_catalog(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        $cashier = \App\Models\User::factory()->staff()->create();

        $this->actingAs($cashier)
            ->get(route('pos.index'))
            ->assertOk()
            ->assertSee('Spam')
            ->assertSee('Purefoods Luncheon Meat');

        // A product from the old demo catalogue is gone from the till.
        $this->actingAs($cashier)
            ->get(route('pos.index', ['q' => 'Cola']))
            ->assertOk()
            ->assertDontSee('Cola 500ml Can');
    }
}
