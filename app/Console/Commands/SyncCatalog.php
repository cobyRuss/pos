<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\CatalogSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ReflectionClass;

/**
 * Make the live catalogue match the store's product list.
 *
 * The store's listing is the record of what it sells, so anything in the
 * database that is not on it is either a leftover from an earlier list or a
 * product that has been discontinued. This command upserts the listing, then
 * reports what is about to be removed.
 *
 * Pruning is opt-in via --prune, and it is not a soft delete:
 *
 *  - `order_items.product_id` is `nullOnDelete`, and `order_items.product_name` is
 *    frozen at the moment of sale, so historic orders keep printing the right
 *    name on the receipt and the right cost in the profit report. A product can
 *    be removed without rewriting history.
 *  - `products.barcode` is unique, so a re-added product claims its code again.
 */
class SyncCatalog extends Command
{
    protected $signature = 'catalog:sync
                            {--prune : Delete products and categories that are not in the store listing}
                            {--dry-run : Report what would change without writing anything}';

    protected $description = 'Sync products and categories with the store product listing';

    public function handle(): int
    {
        $listing = $this->listing();

        $listedProducts = [];
        $listedCategories = [];

        foreach ($listing as $categoryName => $definition) {
            $listedCategories[$categoryName] = true;

            foreach ($definition['products'] as $row) {
                $listedProducts[$row['name']] = true;
            }
        }

        $extraProducts = Product::query()
            ->whereNotIn('name', array_keys($listedProducts))
            ->orderBy('name')
            ->get();

        $extraCategories = Category::query()
            ->whereNotIn('name', array_keys($listedCategories))
            ->orderBy('name')
            ->get();

        $this->info(sprintf(
            'Store listing: %d products across %d categories.',
            count($listedProducts),
            count($listedCategories),
        ));

        if ($extraProducts->isEmpty() && $extraCategories->isEmpty()) {
            $this->info('The catalogue already matches the listing.');

            if (! $this->option('prune')) {
                return self::SUCCESS;
            }
        }

        if (! $extraProducts->isEmpty()) {
            $this->warn(sprintf('%d product(s) not on the listing:', $extraProducts->count()));

            foreach ($extraProducts as $product) {
                $this->line(sprintf(
                    '  - %s (stock %d, barcode %s)',
                    $product->name,
                    (int) $product->stock,
                    $product->barcode ?? 'none',
                ));
            }
        }

        if (! $extraCategories->isEmpty()) {
            $this->warn(sprintf('%d categor(y/ies) not on the listing:', $extraCategories->count()));

            foreach ($extraCategories as $category) {
                $this->line(sprintf('  - %s', $category->name));
            }
        }

        if (! $this->option('prune')) {
            $this->info('Nothing was changed. Re-run with --prune to remove them.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was deleted.');

            return self::SUCCESS;
        }

        // Deleting the products first empties the categories, so no category is
        // dropped while a product still points at it.
        DB::transaction(function () use ($extraProducts, $extraCategories) {
            foreach ($extraProducts as $product) {
                $product->delete();
            }

            foreach ($extraCategories as $category) {
                $category->delete();
            }
        });

        // Categories from a previous listing that the new one still uses are
        // left in place but deactivated, so their product history stays reachable
        // without them cluttering the till's category tabs.
        Category::query()
            ->whereNotIn('name', array_keys($listedCategories))
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $this->info(sprintf(
            'Removed %d product(s) and %d categor(y/ies). Historic orders keep the name and cost frozen on the sale.',
            $extraProducts->count(),
            $extraCategories->count(),
        ));
        $this->info('Now running the listing seeder...');
        $this->call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);

        return self::SUCCESS;
    }

    /**
     * The store's listing, read straight off the seeder's catalogue.
     *
     * @return array<string, array{description: string, products: array<int, array<string, mixed>>}>
     */
    private function listing(): array
    {
        return (new ReflectionClass(CatalogSeeder::class))->getConstant('CATALOG');
    }
}
