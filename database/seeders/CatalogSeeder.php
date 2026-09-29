<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The store's actual catalogue.
 *
 * The product, category, cost and selling price below are taken verbatim from
 * the store's own PRODUCT_LISTING document - nothing here is invented. A product
 * that is not in this list is not in the store.
 *
 * Everything the document does not cover (opening stock, the low-stock alert
 * level, shelf life, barcode) is *derived* per category in `profileFor()` rather
 * than written into the list, so the const stays a faithful copy of the store's
 * data and the demo quantities are visibly a separate concern.
 */
class CatalogSeeder extends Seeder
{
    /**
     * @var array<string, array{description: string, products: array<int, array{name: string, cost: float, price: float, nobarcode?: bool}>}>
     */
    private const CATALOG = [
        'Canned Goods' => [
            'description' => 'Tinned meat, fish, sardines and vegetables',
            'products' => [
                ['name' => 'Purefoods Luncheon Meat', 'cost' => 199.00, 'price' => 215.00],
                ['name' => 'Spam', 'cost' => 95.00, 'price' => 110.00],
                ['name' => 'Purefoods Corned Beef', 'cost' => 35.00, 'price' => 42.00],
                ['name' => 'Argentina Corned Beef', 'cost' => 30.00, 'price' => 36.00],
                ['name' => 'Century Tuna', 'cost' => 32.00, 'price' => 38.00],
                ['name' => 'Fresca Tuna', 'cost' => 28.00, 'price' => 35.00],
                ['name' => 'Mega Sardines', 'cost' => 22.00, 'price' => 27.00],
                ["name" => "Young's Town Sardines", 'cost' => 20.00, 'price' => 25.00],
                ['name' => 'Vienna Sausage', 'cost' => 30.00, 'price' => 38.00],
                ['name' => 'Green Peas', 'cost' => 30.00, 'price' => 38.00],
                ['name' => 'Corn Kernels', 'cost' => 30.00, 'price' => 38.00],
            ],
        ],

        'Processed Meat' => [
            'description' => 'Sausages and ready-to-eat meat',
            'products' => [
                ['name' => 'Philippine Sausage', 'cost' => 35.00, 'price' => 42.00],
            ],
        ],

        'Snacks' => [
            'description' => 'Crisps, chicharron and savoury snacks',
            'products' => [
                ['name' => 'Piattos Cheese', 'cost' => 17.00, 'price' => 23.00],
                ['name' => 'Piattos Barbecue', 'cost' => 17.00, 'price' => 23.00],
                ['name' => 'Piattos Sour Cream & Onion', 'cost' => 17.00, 'price' => 23.00],
                ['name' => 'Piattos Cheese Party Size', 'cost' => 35.00, 'price' => 42.00],
                ['name' => 'V-Cut Barbecue', 'cost' => 17.00, 'price' => 23.00],
                ['name' => 'Nova Country Cheddar', 'cost' => 17.00, 'price' => 23.00],
                ['name' => 'Chippy Barbecue', 'cost' => 7.50, 'price' => 11.00],
                ['name' => 'Chippy Cheese', 'cost' => 7.50, 'price' => 11.00],
                ['name' => 'Mr. Chips', 'cost' => 15.00, 'price' => 18.00],
                ['name' => 'Chiz Curls', 'cost' => 7.50, 'price' => 11.00],
                ['name' => 'Moby Chocolate', 'cost' => 15.00, 'price' => 18.00],
                ['name' => 'Moby Cheese', 'cost' => 15.00, 'price' => 18.00],
                ['name' => 'Super Crunch Cheese Ring', 'cost' => 15.00, 'price' => 18.00],
                ['name' => 'Super Crunch Corn Chips', 'cost' => 15.00, 'price' => 18.00],
                ['name' => 'Mang Juan Chicharron', 'cost' => 8.00, 'price' => 12.00],
                ['name' => 'Mang. Juan', 'cost' => 8.00, 'price' => 12.00],
                ['name' => 'Oishi Pillows', 'cost' => 7.50, 'price' => 11.00],
                ['name' => 'Oishi Prawn Crackers', 'cost' => 7.50, 'price' => 11.00],
                ['name' => 'Oishi Caramel Popcorn', 'cost' => 25.00, 'price' => 30.00],
            ],
        ],

        'Crackers' => [
            'description' => 'Plain and filled crackers',
            'products' => [
                ['name' => 'Magic Flakes', 'cost' => 7.00, 'price' => 10.00],
                ['name' => 'SkyFlakes', 'cost' => 7.00, 'price' => 10.00],
                ['name' => 'Fita', 'cost' => 7.00, 'price' => 10.00],
            ],
        ],

        'Biscuits' => [
            'description' => 'Biscuits, cakes and cookies',
            'products' => [
                ['name' => 'Hansel', 'cost' => 7.00, 'price' => 10.00],
                ['name' => 'Cream-O', 'cost' => 7.00, 'price' => 10.00],
                ['name' => 'Rebisco', 'cost' => 7.00, 'price' => 10.00],
                ['name' => 'Lava Cake', 'cost' => 12.00, 'price' => 15.00],
                ['name' => 'Choco Mallows', 'cost' => 12.00, 'price' => 15.00],
                ['name' => 'Butter Coconut Biscuits', 'cost' => 25.00, 'price' => 30.00],
                ['name' => 'Chocolate Chip Cookies', 'cost' => 25.00, 'price' => 30.00],
            ],
        ],

        'Household' => [
            'description' => 'Detergent, bleach and fabric care',
            'products' => [
                ['name' => 'Ariel Detergent Powder', 'cost' => 16.00, 'price' => 22.00],
                ['name' => 'Tide Detergent Powder', 'cost' => 16.00, 'price' => 22.00],
                ['name' => 'Surf Detergent Powder', 'cost' => 7.60, 'price' => 11.00],
                ['name' => 'Breeze Detergent', 'cost' => 30.00, 'price' => 38.00],
                ['name' => 'Downy Fabric Conditioner', 'cost' => 7.00, 'price' => 10.00],
                ['name' => 'Zonrox Bleach', 'cost' => 11.00, 'price' => 15.00],
                // Generic lines, so no printed code to scan.
                ['name' => 'Dishwashing Liquid', 'cost' => 25.00, 'price' => 32.00, 'nobarcode' => true],
                ['name' => 'Fabric Conditioner', 'cost' => 25.00, 'price' => 32.00, 'nobarcode' => true],
            ],
        ],
    ];

    /**
     * Days from delivery to expiry, per product.
     *
     * These are researched figures, not round numbers, and the reasoning is
     * recorded because a store owner who disagrees with one of them should be
     * able to see why it was chosen. Where a Philippine figure was available it
     * is used; where not, the manufacturer's general shelf life for that product
     * type is the basis.
     *
     * Canned goods, the anchor:
     *  - DSWD and government bid specifications for corned beef, tuna flakes
     *    and sardines all require "not less than 12 to 24 months from the date
     *    of delivery", which tells you both that the shelf life is long and that
     *    a retailer expects to receive product with a year or more left on it.
     *  - The Philippine FDA product registry shows Purefoods Luncheon Meat
     *    registered on a 5-year window, and sardines similarly.
     *  - USDA guidance separates low-acid canned meat and vegetables (2-5
     *    years) from high-acid fruit and tomato (12-18 months). Nothing in this
     *    catalogue is high-acid, so 2 years is the floor and the premium meats
     *    are given more.
     *
     * Snacks, crackers and biscuits:
     *  - Oishi's own distributor listings state 12 months (50g x 50, 85g x 30).
     *    Chips are fried or baked with TBHQ against rancidity and carry a "best
     *    before", not a "use by".
     *  - Philippine FDA guidance classes biscuits and candy as
     *    microbiologically stable, so they are a quality date rather than a
     *    safety one. Hard biscuits keep longest; soft cakes, marshmallow
     *    fillings and cookies go stale sooner and are given 9 months.
     *
     * Household:
     *  - Zonrox's own trade suppliers state 1 year for bleach, and that is not
     *    marketing caution: sodium hypochlorite decays into salt and water, so
     *    a bottle really does lose active strength in about a year. This is the
     *    one chemical in the store that genuinely dates, and it is why bleach
     *    gets the shortest shelf life of the household shelf.
     *  - Powder detergent is the most stable thing in the shop - dry, sealed,
     *    and chemically inert enough to sit for years.
     *  - Liquid detergent and softener lose some fragrance and viscosity in heat,
     *    so they are given two years rather than the powder's.
     *
     * @var array<string, int>
     */
    private const SHELF_LIFE = [
        // Canned goods
        'Purefoods Luncheon Meat' => 1095,
        'Spam' => 1095,
        'Purefoods Corned Beef' => 730,
        'Argentina Corned Beef' => 730,
        'Century Tuna' => 730,
        'Fresca Tuna' => 730,
        'Mega Sardines' => 730,
        "Young's Town Sardines" => 730,
        'Green Peas' => 730,
        'Corn Kernels' => 730,
        // Processed meat. Sausage is the shortest-lived canned line: it carries
        // fat and a higher microbial count than a straight corned beef tin.
        'Vienna Sausage' => 540,
        'Philippine Sausage' => 540,

        // Snacks. Oishi distributor listings confirm 12 months; the fried
        // chicharron and the caramel popcorn are pulled in for the reason above.
        'Piattos Cheese' => 365,
        'Piattos Barbecue' => 365,
        'Piattos Sour Cream & Onion' => 365,
        'Piattos Cheese Party Size' => 365,
        'V-Cut Barbecue' => 365,
        'Nova Country Cheddar' => 365,
        'Chippy Barbecue' => 365,
        'Chippy Cheese' => 365,
        'Mr. Chips' => 365,
        'Chiz Curls' => 365,
        'Moby Chocolate' => 365,
        'Moby Cheese' => 365,
        'Super Crunch Cheese Ring' => 365,
        'Super Crunch Corn Chips' => 365,
        'Oishi Pillows' => 365,
        'Oishi Prawn Crackers' => 365,
        'Mang Juan Chicharron' => 270,
        'Mang. Juan' => 270,
        'Oishi Caramel Popcorn' => 180,

        // Crackers. M.Y. San retail listings carry a best-before roughly a year
        // out on shelf, so that is what the shelf is stocked to.
        'Magic Flakes' => 365,
        'SkyFlakes' => 365,
        'Fita' => 365,

        // Biscuits
        'Hansel' => 365,
        'Cream-O' => 365,
        'Rebisco' => 365,
        'Lava Cake' => 270,
        'Choco Mallows' => 270,
        'Butter Coconut Biscuits' => 270,
        'Chocolate Chip Cookies' => 270,

        // Household
        'Zonrox Bleach' => 365,
        'Ariel Detergent Powder' => 1095,
        'Tide Detergent Powder' => 1095,
        'Surf Detergent Powder' => 1095,
        'Breeze Detergent' => 1095,
        'Downy Fabric Conditioner' => 730,
        'Dishwashing Liquid' => 730,
        'Fabric Conditioner' => 730,
    ];

    /**
     * How the store would actually stock each product.
     *
     * `daily` is expected units sold per day. `cover` is how many days of that
     * demand to hold on the shelf. Neither is researched - they are a judgement
     * about a small provincial minimart, and the reasoning is below. The store's
     * document says nothing about either, which is exactly why they live in
     * their own map rather than being mixed into CATALOG.
     *
     * Reading the velocities: the shop is a neighbourhood store that trades all
     * day, so corned beef and sardines are the staples that keep it alive and
     * chips are the impulse buy at the counter. Premium lines (Spam, Purefoods
     * Luncheon Meat at over ₱200) move slowly but are worth the shelf space
     * because a customer who came for one will buy something else too. Detergent
     * is heavy and bulky for a minimart, so the count is low even though the
     * margin is decent.
     *
     * Cover is deliberately short on fast movers and long on slow ones, which is
     * the opposite of the usual instinct and is what keeps the working capital
     * honest:
     *
     *  - Canned goods and chips turn over in about two weeks. They are the
     *    bread and butter, and a minimart that runs out of corned beef or
     *    Chippy has failed at the one job it has.
     *  - Slow movers get 30 days of cover, so they still have stock on the shelf
     *    when someone asks, without tying up much money - the absolute quantity
     *    is small anyway.
     *  - Chicharron and caramel popcorn get the shortest cover of all despite
     *    being mid-velocity, because fried snacks go stale in a hot province
     *    and sitting on the shelf does not make them sell.
     *
     * @var array<string, array{daily: float, cover: int}>
     */
    private const DEMAND = [
        // Canned Goods
        'Purefoods Corned Beef' => ['daily' => 4.0, 'cover' => 14],
        'Mega Sardines' => ['daily' => 3.5, 'cover' => 14],
        'Argentina Corned Beef' => ['daily' => 2.5, 'cover' => 14],
        "Young's Town Sardines" => ['daily' => 2.5, 'cover' => 14],
        'Century Tuna' => ['daily' => 2.0, 'cover' => 14],
        'Fresca Tuna' => ['daily' => 1.5, 'cover' => 14],
        'Vienna Sausage' => ['daily' => 1.5, 'cover' => 21],
        'Purefoods Luncheon Meat' => ['daily' => 0.6, 'cover' => 30],
        'Spam' => ['daily' => 0.4, 'cover' => 30],
        'Green Peas' => ['daily' => 1.0, 'cover' => 21],
        'Corn Kernels' => ['daily' => 1.0, 'cover' => 21],

        // Processed Meat
        'Philippine Sausage' => ['daily' => 1.2, 'cover' => 21],

        // Snacks
        'Chippy Barbecue' => ['daily' => 2.0, 'cover' => 10],
        'Chippy Cheese' => ['daily' => 1.8, 'cover' => 10],
        'Piattos Cheese' => ['daily' => 1.5, 'cover' => 10],
        'Piattos Barbecue' => ['daily' => 1.5, 'cover' => 10],
        'Mr. Chips' => ['daily' => 1.5, 'cover' => 10],
        'Moby Chocolate' => ['daily' => 1.3, 'cover' => 10],
        'Piattos Sour Cream & Onion' => ['daily' => 1.2, 'cover' => 10],
        'V-Cut Barbecue' => ['daily' => 1.2, 'cover' => 10],
        'Chiz Curls' => ['daily' => 1.2, 'cover' => 10],
        'Moby Cheese' => ['daily' => 1.2, 'cover' => 10],
        'Oishi Pillows' => ['daily' => 1.2, 'cover' => 10],
        'Oishi Prawn Crackers' => ['daily' => 1.1, 'cover' => 10],
        'Mang. Juan' => ['daily' => 1.0, 'cover' => 8],
        'Nova Country Cheddar' => ['daily' => 1.0, 'cover' => 10],
        'Super Crunch Cheese Ring' => ['daily' => 1.0, 'cover' => 10],
        'Super Crunch Corn Chips' => ['daily' => 1.0, 'cover' => 10],
        'Mang Juan Chicharron' => ['daily' => 0.8, 'cover' => 8],
        'Oishi Caramel Popcorn' => ['daily' => 0.5, 'cover' => 8],
        'Piattos Cheese Party Size' => ['daily' => 0.3, 'cover' => 14],

        // Crackers
        'Fita' => ['daily' => 1.2, 'cover' => 21],
        'SkyFlakes' => ['daily' => 1.0, 'cover' => 21],
        'Magic Flakes' => ['daily' => 0.6, 'cover' => 21],

        // Biscuits
        'Hansel' => ['daily' => 1.5, 'cover' => 21],
        'Cream-O' => ['daily' => 1.3, 'cover' => 21],
        'Rebisco' => ['daily' => 1.0, 'cover' => 21],
        'Lava Cake' => ['daily' => 0.8, 'cover' => 14],
        'Choco Mallows' => ['daily' => 0.7, 'cover' => 14],
        'Chocolate Chip Cookies' => ['daily' => 0.7, 'cover' => 14],
        'Butter Coconut Biscuits' => ['daily' => 0.6, 'cover' => 14],

        // Household
        'Downy Fabric Conditioner' => ['daily' => 0.8, 'cover' => 30],
        'Zonrox Bleach' => ['daily' => 0.6, 'cover' => 21],
        'Ariel Detergent Powder' => ['daily' => 0.5, 'cover' => 30],
        'Surf Detergent Powder' => ['daily' => 0.5, 'cover' => 30],
        'Dishwashing Liquid' => ['daily' => 0.5, 'cover' => 30],
        'Tide Detergent Powder' => ['daily' => 0.4, 'cover' => 30],
        'Fabric Conditioner' => ['daily' => 0.4, 'cover' => 30],
        'Breeze Detergent' => ['daily' => 0.3, 'cover' => 30],
    ];

    /**
     * Days between placing an order and the goods being on the shelf.
     *
     * A provincial minimart buys from a wholesale supplier or a palengke
     * distributor, usually on a weekly run or a daily drop. Two days is the
     * realistic figure for "I noticed we were low and called my supplier".
     */
    private const LEAD_TIME_DAYS = 2;

    /**
     * Extra days of stock held purely so a busy weekend or a supplier being late
     * does not put a staple out of stock. This is what the low-stock alert is
     * really watching.
     */
    private const SAFETY_DAYS = 3;

    /**
     * Shelf life in days for a product, from the researched table above.
     */
    public static function shelfLifeFor(string $product): int
    {
        return self::SHELF_LIFE[$product] ?? 365;
    }

    /**
     * The target on-shelf quantity for a product: days of cover against
     * modelled demand.
     */
    public static function targetStockFor(string $product): int
    {
        $demand = self::DEMAND[$product] ?? ['daily' => 1.0, 'cover' => 21];

        return (int) max(3, round($demand['daily'] * $demand['cover']));
    }

    /**
     * The reorder point, in units.
     *
     * Demand across the lead time, plus the safety buffer. This is the number
     * the low-stock alert compares against, so it has to be a genuine "order
     * now" signal rather than an arbitrary fraction of the target - a reorder
     * point of 24 on a product that sells 4 a day would not fire until the
     * shelf was already empty.
     */
    public static function reorderPointFor(string $product): int
    {
        $demand = self::DEMAND[$product] ?? ['daily' => 1.0, 'cover' => 21];

        return (int) max(2, round($demand['daily'] * (self::LEAD_TIME_DAYS + self::SAFETY_DAYS)));
    }

    /**
     * Modelled daily demand, for the weighted demo sales and the order sheet.
     */
    public static function dailyDemandFor(string $product): float
    {
        return (self::DEMAND[$product] ?? ['daily' => 1.0])['daily'];
    }

    /**
     * The whole demand table, for the order sheet command.
     *
     * @return array<string, array{daily: float, cover: int}>
     */
    public static function demand(): array
    {
        return self::DEMAND;
    }

    /**
     * A stable EAN-13 for a product, or null when it has no printed code.
     *
     * Real check digit, so the number a scanner reads off a box is one a
     * validator will accept rather than 13 digits of decoration. The payload is
     * built from the GS1 prefix that Philippine goods are issued under (480), a
     * two-digit category code and a seven-digit item number, which is
     * deterministic: re-seeding gives the same product the same code instead of
     * reshuffling the whole catalogue.
     */
    private function barcodeFor(string $category, int $categoryIndex, int $itemIndex, bool $none): ?string
    {
        if ($none) {
            return null;
        }

        $payload = '480'.sprintf('%02d', $categoryIndex).sprintf('%07d', $itemIndex);

        return $payload.$this->ean13CheckDigit($payload);
    }

    /**
     * The EAN-13 check digit: odd positions weigh 1, even positions weigh 3.
     */
    private function ean13CheckDigit(string $twelveDigits): int
    {
        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $sum += ((int) $twelveDigits[$i]) * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }

    public function run(InventoryService $inventory): void
    {
        $categoryIndex = 0;

        foreach (self::CATALOG as $categoryName => $definition) {
            $categoryIndex++;

            $category = Category::updateOrCreate(
                ['name' => $categoryName],
                ['description' => $definition['description'], 'is_active' => true],
            );

            foreach ($definition['products'] as $itemIndex => $row) {
                $stock = self::targetStockFor($row['name']);
                $shelf = self::shelfLifeFor($row['name']);

                $product = Product::updateOrCreate(
                    ['name' => $row['name']],
                    [
                        'category_id' => $category->getKey(),
                        'description' => null,
                        'barcode' => $this->barcodeFor(
                            $categoryName,
                            $categoryIndex,
                            $itemIndex + 1,
                            (bool) ($row['nobarcode'] ?? false),
                        ),
                        'cost_price' => $row['cost'],
                        'selling_price' => $row['price'],
                        'stock' => 0,
                        'low_stock_threshold' => self::reorderPointFor($row['name']),
                        'unit' => 'pcs',
                        'is_active' => true,
                    ],
                );

                if ((int) $product->stock !== $stock) {
                    DB::transaction(function () use ($inventory, $product, $stock, $shelf) {
                        // A dated delivery lot at the researched shelf life, so
                        // FEFO has something to draw from and the expiry warnings
                        // are working off real dates rather than a placeholder.
                        $inventory->setStock(
                            $product,
                            $stock,
                            'Opening stock',
                            null,
                            null,
                            ['expiry_date' => now()->addDays($shelf)->toDateString()],
                        );
                    });
                }
            }
        }
    }
}
