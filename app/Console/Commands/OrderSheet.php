<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Support\DateFormat;
use Database\Seeders\CatalogSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The supplier order sheet.
 *
 * A small store runs on a notebook, and a notebook does not know that a product
 * selling four tins a day needs reordering at twenty-eight while a product
 * selling one every three days can wait. This prints the list the owner would
 * otherwise have to work out by hand, and it answers a direct question: for each
 * product, how much should I actually be holding and how much should I tell the
 * supplier to bring.
 *
 * The arithmetic is deliberately simple and visible, because a store owner has
 * to be able to disagree with it:
 *
 *   target       = daily demand x days of cover
 *   reorder at   = daily demand x (lead time + safety stock)
 *   order now    = target - what is on the shelf, only once it drops to the
 *                  reorder point
 *
 * No exponential smoothing, no forecasting. For a shop this size the judgement
 * is in the demand and cover numbers, and hiding that behind a moving average
 * would make the output impossible to argue with.
 */
class OrderSheet extends Command
{
    protected $signature = 'catalog:order-sheet
                            {--all : List every product, not just the ones at or below their reorder point}
                            {--category= : Limit to one category}
                            {--export= : Write a CSV to the given path}';

    protected $description = 'Print a supplier order sheet from the store demand model';

    public function handle(): int
    {
        $rows = $this->rows();

        if ($path = $this->option('export')) {
            // Written even when the sheet is empty. A header-only CSV is a
            // meaningful answer - "nothing to order today" - whereas no file at
            // all looks like the command never ran.
            $this->writeCsv($path, $rows);
            $this->info(sprintf(
                'Wrote %d line(s) to %s%s',
                $rows->count(),
                $path,
                $rows->isEmpty() ? ' (nothing below its reorder point)' : '',
            ));

            return self::SUCCESS;
        }

        if ($rows->isEmpty()) {
            $this->info('Nothing to order. Every product is above its reorder point.');

            return self::SUCCESS;
        }

        $this->renderTable($rows);

        $this->newLine();
        $this->line(sprintf(
            '<fg=gray>%s · %d line(s) · estimated cost %s at cost price%s</fg=gray>',
            DateFormat::date(now()),
            $rows->count(),
            \App\Models\Setting::money($rows->sum('order_cost')),
            $this->option('all') ? '' : ' (only products at or below their reorder point)',
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, object>
     */
    private function rows(): Collection
    {
        $categoryId = $this->option('category')
            ? Category::where('name', $this->option('category'))->value('id')
            : null;

        if ($this->option('category') && $categoryId === null) {
            $this->error(sprintf('No category called "%s".', $this->option('category')));

            return collect();
        }

        return Product::query()
            ->with('category')
            ->where('is_active', true)
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->orderBy('category_id')
            ->get()
            ->map(function (Product $product) {
                $name = $product->name;
                $target = CatalogSeeder::targetStockFor($name);
                $reorder = CatalogSeeder::reorderPointFor($name);
                $onHand = (int) $product->stock;
                $needsOrder = $onHand <= $reorder;
                $order = max(0, $target - $onHand);

                return (object) [
                    'category' => $product->category?->name ?? 'Uncategorised',
                    'name' => $name,
                    'on_hand' => $onHand,
                    'reorder_at' => $reorder,
                    'target' => $target,
                    'order' => $needsOrder ? $order : 0,
                    'daily' => CatalogSeeder::dailyDemandFor($name),
                    'days_cover_left' => $this->daysOfCoverLeft($product, $name),
                    'shelf_days' => CatalogSeeder::shelfLifeFor($name),
                    'cost' => (float) $product->cost_price,
                    'order_cost' => $needsOrder ? round($order * (float) $product->cost_price, 2) : 0.0,
                ];
            })
            ->when(
                fn () => ! $this->option('all'),
                fn ($rows) => $rows->filter(fn ($row) => $row->order > 0),
            )
            // Grouped by category so the sheet can be handed to a supplier
            // section by section, and sorted most-urgent-first *within* each
            // group. Sorting the flat list by urgency instead would interleave
            // the categories and repeat every heading.
            ->sortBy([
                fn ($a, $b) => $a->category <=> $b->category,
                fn ($a, $b) => $a->days_cover_left <=> $b->days_cover_left,
                fn ($a, $b) => $b->daily <=> $a->daily,
            ])
            ->values();
    }

    /**
     * How many days the stock currently on the shelf will last at the modelled
     * rate. This is the number that actually decides the order: a reorder point
     * of 12 matters differently when it is 12 days of stock left or one.
     */
    private function daysOfCoverLeft(Product $product, string $name): float
    {
        $daily = CatalogSeeder::dailyDemandFor($name);

        if ($daily <= 0) {
            return PHP_FLOAT_MAX;
        }

        return round((int) $product->stock / $daily, 1);
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function renderTable(Collection $rows): void
    {
        $lastCategory = null;

        foreach ($rows as $row) {
            if ($row->category !== $lastCategory) {
                if ($lastCategory !== null) {
                    $this->newLine();
                }

                $lastCategory = $row->category;
                $this->line("<fg=cyan;options=bold>{$row->category}</>");
            }

            $this->line(sprintf(
                '  %-30s %4d on hand   %5.1f days left   order <fg=yellow;options=bold>%4d</>   reorder at %3d   target %3d',
                mb_strimwidth($row->name, 0, 30),
                $row->on_hand,
                $row->days_cover_left,
                $row->order,
                $row->reorder_at,
                $row->target,
            ));
        }
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function writeCsv(string $path, Collection $rows): void
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            $this->error("Could not write to {$path}.");

            return;
        }

        fputcsv($handle, [
            'Category', 'Product', 'On hand', 'Days of cover left', 'Daily demand',
            'Reorder point', 'Target stock', 'Order quantity', 'Cost each', 'Order cost',
            'Shelf life (days)',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row->category,
                $row->name,
                $row->on_hand,
                $row->days_cover_left,
                $row->daily,
                $row->reorder_at,
                $row->target,
                $row->order,
                $row->cost,
                $row->order_cost,
                $row->shelf_days,
            ]);
        }

        fclose($handle);
    }
}
