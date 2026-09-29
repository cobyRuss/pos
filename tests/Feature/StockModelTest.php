<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Support\DateFormat;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

/**
 * The stock model is a judgement, not a fact, so these tests are mostly about
 * the *rules* the judgement has to obey. A demand model that quietly produces a
 * reorder point above the target, or a shelf life of zero, would make the order
 * sheet confidently wrong - which is worse than not having one.
 */
class StockModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{description: string, products: array<int, array<string, mixed>>}>
     */
    private function listing(): array
    {
        return (new ReflectionClass(CatalogSeeder::class))->getConstant('CATALOG');
    }

    public function test_every_product_on_the_listing_has_a_demand_figure(): void
    {
        $demand = CatalogSeeder::demand();

        foreach ($this->listing() as $categoryName => $definition) {
            foreach ($definition['products'] as $row) {
                $this->assertArrayHasKey(
                    $row['name'],
                    $demand,
                    $row['name'].' has no demand figure, so it would fall back to a default and quietly mis-order.',
                );
            }
        }

        $this->assertCount(49, $demand, 'There should be exactly one demand figure per listed product.');
    }

    public function test_every_product_has_a_researched_shelf_life(): void
    {
        foreach ($this->listing() as $definition) {
            foreach ($definition['products'] as $row) {
                $shelf = CatalogSeeder::shelfLifeFor($row['name']);

                $this->assertGreaterThan(0, $shelf, $row['name'].' has no shelf life.');
                $this->assertLessThanOrEqual(1825, $shelf, $row['name'].' has an implausible shelf life.');
            }
        }
    }

    public function test_bleach_is_the_shortest_lived_thing_in_the_household_shelf(): void
    {
        // Sodium hypochlorite decays into salt and water, so a bottle really does
        // lose strength in about a year while the powder next to it sits for
        // three. This is the assumption most worth pinning, because getting it
        // backwards would mean selling bleach that no longer bleaches.
        $this->assertSame(365, CatalogSeeder::shelfLifeFor('Zonrox Bleach'));
        $this->assertSame(1095, CatalogSeeder::shelfLifeFor('Ariel Detergent Powder'));

        foreach (['Ariel Detergent Powder', 'Tide Detergent Powder', 'Surf Detergent Powder', 'Breeze Detergent'] as $powder) {
            $this->assertGreaterThan(
                CatalogSeeder::shelfLifeFor('Zonrox Bleach'),
                CatalogSeeder::shelfLifeFor($powder),
                $powder.' should outlast the bleach.',
            );
        }
    }

    public function test_snacks_never_outlive_the_canned_goods(): void
    {
        // Fried and baked snacks go stale; a tin does not. If this inverts, the
        // FEFO logic would be selling stale chips in preference to good tins.
        $this->assertLessThan(
            CatalogSeeder::shelfLifeFor('Purefoods Corned Beef'),
            CatalogSeeder::shelfLifeFor('Chippy Barbecue'),
        );
        $this->assertLessThan(
            CatalogSeeder::shelfLifeFor('Century Tuna'),
            CatalogSeeder::shelfLifeFor('Oishi Caramel Popcorn'),
        );
    }

    public function test_a_reorder_point_is_always_below_the_target_stock(): void
    {
        // If the reorder point reached the target, the product would trigger a
        // replenishment order the moment it was full and the sheet would ask for
        // goods the shop already had.
        foreach (array_keys(CatalogSeeder::demand()) as $name) {
            $this->assertLessThan(
                CatalogSeeder::targetStockFor($name),
                CatalogSeeder::reorderPointFor($name),
                $name.' would reorder when already full.',
            );
        }
    }

    public function test_a_reorder_point_is_roughly_five_days_of_demand(): void
    {
        // Two days of lead time plus three days of safety. A product selling ten
        // a day should reorder at about fifty, not at five.
        $this->assertSame(20, CatalogSeeder::reorderPointFor('Purefoods Corned Beef'));
        $this->assertSame(3, CatalogSeeder::reorderPointFor('Zonrox Bleach'));
        $this->assertSame(2, CatalogSeeder::reorderPointFor('Breeze Detergent'), 'A floor of 2 protects very slow movers from a zero threshold.');
    }

    public function test_staples_are_stocked_deeper_than_the_products_they_outsell(): void
    {
        // Corned beef is the store's bread and butter. A minimart that runs out
        // of it has failed at the one job it has, so it carries more of it than
        // of a premium line that moves a fraction as fast.
        $this->assertGreaterThan(
            CatalogSeeder::dailyDemandFor('Spam'),
            CatalogSeeder::dailyDemandFor('Purefoods Corned Beef'),
        );
        $this->assertGreaterThan(
            CatalogSeeder::targetStockFor('Spam'),
            CatalogSeeder::targetStockFor('Purefoods Corned Beef'),
        );
    }

    public function test_the_seeded_catalogue_uses_the_model(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        foreach (Product::all() as $product) {
            $this->assertSame(
                (int) CatalogSeeder::reorderPointFor($product->name),
                (int) $product->low_stock_threshold,
                $product->name.' has the wrong reorder point.',
            );
        }
    }

    public function test_seeded_lots_carry_the_researched_expiry(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        $cornedBeef = Product::where('name', 'Purefoods Corned Beef')->sole();
        $soonest = $cornedBeef->batches()->orderBy('expiry_date')->first();

        $days = (int) now()->startOfDay()->diffInDays($soonest->expiry_date->startOfDay(), false);

        $this->assertSame(730, $days, 'The corned beef lot should be dated two years out.');

        // A snack lot must be much shorter, or FEFO has nothing meaningful to do.
        $chips = Product::where('name', 'Chippy Barbecue')->sole();
        $chipDays = (int) now()->startOfDay()->diffInDays(
            $chips->batches()->orderBy('expiry_date')->first()->expiry_date->startOfDay(),
            false,
        );

        $this->assertSame(365, $chipDays);
    }

    public function test_no_lot_is_seeded_already_expired(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        // A seeded lot that is already past its date would block the till from
        // selling the product at all, which is not a demo anyone wants.
        $this->assertSame(0, Product::sum('expired_stock'));
        $this->assertGreaterThan(0, Product::where('stock', '>', 0)->count());
    }

    public function test_the_order_sheet_lists_a_product_below_its_reorder_point(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        // Empty the shop, so everything needs ordering.
        Product::query()->update(['stock' => 0]);

        $path = storage_path('framework/testing/order.csv');
        Artisan::call('catalog:order-sheet', ['--export' => $path]);

        $csv = file_get_contents($path);

        // fputcsv quotes any field containing a space or comma, so the header
        // reads "On hand" with quotes. Match on the parts that are not.
        $this->assertStringContainsString('Category,Product,', $csv);
        $this->assertStringContainsString('Order quantity', $csv);
        $this->assertStringContainsString('"Purefoods Corned Beef"', $csv);

        // An empty shelf must produce a full replenishment order, not a token one.
        $this->assertMatchesRegularExpression(
            '/"Purefoods Corned Beef",0,0,4,20,56,56,/',
            $csv,
            'Corned beef should ask for its full 56-unit target from an empty shelf.',
        );
    }

    public function test_the_order_sheet_is_empty_when_everything_is_above_its_reorder_point(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        // Fill every shelf to its target, so nothing should be ordered.
        foreach (Product::all() as $product) {
            $product->forceFill(['stock' => CatalogSeeder::targetStockFor($product->name)])->save();
        }

        Artisan::call('catalog:order-sheet', ['--export' => storage_path('framework/testing/order-full.csv')]);

        $csv = file_get_contents(storage_path('framework/testing/order-full.csv'));

        // Header only.
        $this->assertSame(1, count(array_filter(explode("\n", trim($csv)))));
    }

    public function test_the_order_sheet_shows_urgency_within_each_category(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);
        Product::query()->update(['stock' => 0]);

        Artisan::call('catalog:order-sheet');
        $output = Artisan::output();

        $this->assertStringContainsString('Canned Goods', $output);
        $this->assertStringContainsString('Purefoods Corned Beef', $output);
        $this->assertStringContainsString('order', $output);
        $this->assertStringContainsString('estimated cost', $output);
    }

    public function test_the_order_sheet_dated_line_uses_the_house_date_style(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);
        Product::query()->update(['stock' => 0]);

        Artisan::call('catalog:order-sheet');
        $output = Artisan::output();

        $this->assertStringContainsString(DateFormat::date(now()), $output);
        $this->assertStringNotContainsString(now()->format('Y-m-d'), $output);
    }

    public function test_the_order_sheet_can_be_limited_to_one_category(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);
        Product::query()->update(['stock' => 0]);

        Artisan::call('catalog:order-sheet', ['--category' => 'Household']);
        $output = Artisan::output();

        $this->assertStringContainsString('Zonrox Bleach', $output);
        $this->assertStringNotContainsString('Chippy Barbecue', $output);
    }

    public function test_an_unknown_category_is_reported_rather_than_silently_empty(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        Artisan::call('catalog:order-sheet', ['--category' => 'Bakery']);

        // A misspelled category returning a blank sheet would look exactly like
        // "nothing to order", which is the wrong conclusion entirely.
        $this->assertStringContainsString('No category called', Artisan::output());
    }

    public function test_the_whole_catalogue_lands_in_a_realistic_value_range(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        $value = 0.0;
        foreach (Product::all() as $product) {
            $value += (int) $product->stock * (float) $product->cost_price;
        }

        // Working inventory for a small provincial minimart. Wide bounds on
        // purpose: the point is to catch a model that is off by an order of
        // magnitude, not to pin an exact peso figure.
        $this->assertGreaterThan(10000, $value, 'Stock at cost is implausibly low for a stocked-up shop.');
        $this->assertLessThan(60000, $value, 'Stock at cost is implausibly high - too much cash tied up.');
    }

    public function test_daily_demand_totals_something_a_small_store_could_actually_sell(): void
    {
        $total = array_sum(array_column(CatalogSeeder::demand(), 'daily'));

        // Around 58 units a day across 49 lines: roughly 25-30 baskets at two to
        // three items each. A small provincial minimart, trading all day.
        $this->assertGreaterThan(40, $total, 'Modelled demand is too low to be a working shop.');
        $this->assertLessThan(90, $total, 'Modelled demand is too high for a small standalone store.');
    }

    public function test_the_slowest_mover_is_not_the_biggest_line_item(): void
    {
        $demand = CatalogSeeder::demand();

        $slowest = collect($demand)->sortBy(fn ($d) => $d['daily'])->first();
        $biggest = collect($demand)->sortByDesc(fn ($d) => $d['daily'])->first();

        $this->assertLessThan(
            CatalogSeeder::targetStockFor(array_search($biggest, $demand, true)),
            CatalogSeeder::targetStockFor(array_search($slowest, $demand, true)),
            'The fastest mover should be the deepest line on the shelf.',
        );
    }

    public function test_the_demo_sales_follow_the_demand_model(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        // The sales seeder needs somebody to ring the sales up, and quietly does
        // nothing without them.
        Artisan::call('db:seed', ['--class' => \Database\Seeders\UserSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => \Database\Seeders\DemoSalesSeeder::class, '--force' => true]);

        // Read what actually sold, from the sales themselves, rather than
        // inferring it from stock levels the restock seeder has already moved.
        $sold = \App\Models\OrderItem::query()
            ->whereHas('product', fn ($q) => $q->whereIn('name', [
                'Purefoods Corned Beef',
                'Breeze Detergent',
                'Spam',
            ]))
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->groupBy('products.name')
            ->selectRaw('products.name, SUM(order_items.quantity) as qty')
            ->pluck('qty', 'name');

        $this->assertGreaterThan(0, $sold->get('Purefoods Corned Beef', 0));

        // Weighted selection means the staples come through noticeably harder
        // than the long tail. If the seeder went back to uniform random, these
        // would land in the same place and this would stop proving anything.
        $this->assertGreaterThan(
            (int) $sold->get('Breeze Detergent', 0),
            (int) $sold->get('Purefoods Corned Beef', 0),
            'Corned beef should sell far more units than a slow-moving detergent.',
        );
    }

    public function test_a_full_seed_leaves_no_undated_lots(): void
    {
        // The regression this guards: a sold-out product restocked by the demo
        // seeder used to land in the undated "general bucket", because
        // InventoryService creates one whenever an adjustment supplies no expiry
        // date. That default is right for a real stock count of unknown vintage,
        // but the seeder knows the vintage, and an undated lot can never be
        // flagged as expiring - which quietly disables FEFO and the expiry
        // warnings for exactly the products most likely to go stale.
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => \Database\Seeders\UserSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => \Database\Seeders\DemoSalesSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => \Database\Seeders\RestockSeeder::class, '--force' => true]);

        $undated = \App\Models\ProductBatch::query()->whereNull('expiry_date')->count();

        $this->assertSame(0, $undated, 'A seeded lot with no expiry date can never be flagged as expiring.');

        // And the dated ones still carry the researched shelf life.
        $chips = Product::where('name', 'Chippy Barbecue')->sole();

        $this->assertGreaterThan(0, $chips->batches()->whereNotNull('expiry_date')->count());
    }

    public function test_demand_weighting_holds_across_the_whole_catalogue(): void
    {
        Artisan::call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => \Database\Seeders\UserSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => \Database\Seeders\DemoSalesSeeder::class, '--force' => true]);

        // A single slow-mover comparison is noise at these sample sizes, so this
        // checks the shape of the whole distribution instead: the products the
        // model calls fast must account for a disproportionate share of the units
        // sold. Uniform random selection would put that share at the population
        // average, which is the failure this guards.
        $totalSold = (int) \App\Models\OrderItem::query()->sum('quantity');

        $fastSold = (int) \App\Models\OrderItem::query()
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('products.name', ['Purefoods Corned Beef', 'Young\'s Town Sardines', 'Mega Sardines', 'Century Tuna'])
            ->sum('order_items.quantity');

        $fastNames = 4;
        $allNames = 49;

        $shareOfUnits = $fastSold / max(1, $totalSold);
        $shareOfProducts = $fastNames / $allNames;

        $this->assertGreaterThan(
            $shareOfProducts,
            $shareOfUnits,
            sprintf(
                'Four staples out of 49 products are %.1f%% of the catalogue but only sold %.1f%% of the units. The demo sales are not following the demand model.',
                $shareOfProducts * 100,
                $shareOfUnits * 100,
            ),
        );
    }
}
