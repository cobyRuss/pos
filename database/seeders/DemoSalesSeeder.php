<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Exceptions\InsufficientStockException;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Rings up 30 days of believable sales through the real OrderService, so stock
 * levels, movements, order numbers and revenue all stay consistent.
 */
class DemoSalesSeeder extends Seeder
{
    public function run(OrderService $orders): void
    {
        $cashiers = User::where('is_active', true)->get();

        if ($cashiers->isEmpty()) {
            $this->command?->warn('No active users found - run UserSeeder first.');

            return;
        }

        $products = Product::where('is_active', true)->where('stock', '>', 4)->get();

        if ($products->isEmpty()) {
            $this->command?->warn('No sellable products found - run CatalogSeeder first.');

            return;
        }

        $methods = [PaymentMethod::Cash, PaymentMethod::Gcash, PaymentMethod::Cash, PaymentMethod::Gcash, PaymentMethod::Cash];

        for ($day = 29; $day >= 0; $day--) {
            // Busier at weekends, quiet on Mondays.
            $date = Carbon::today()->subDays($day);
            $isWeekend = $date->isWeekend();
            $ordersToday = random_int($isWeekend ? 6 : 3, $isWeekend ? 11 : 7);

            for ($n = 0; $n < $ordersToday; $n++) {
                $cashier = $cashiers->random();
                $lines = [];

                // Products are drawn weighted by modelled demand, not uniformly.
                // This is what makes the stock model checkable: after 30 days the
                // staples should be visibly drawn down and the slow movers should
                // be almost untouched. Uniform selection would flatten the
                // difference and the order sheet would have nothing to say.
                foreach ($this->weightedPick($products, random_int(1, 5)) as $product) {
                    $existing = $lines[$product->getKey()] ?? null;
                    $quantity = random_int(1, 3);

                    if ($existing) {
                        $quantity += $existing['quantity'];
                    }

                    $lines[$product->getKey()] = [
                        'quantity' => $quantity,
                        'unit_price' => (float) $product->selling_price,
                    ];
                }

                $method = $methods[array_rand($methods)];

                // Trading hours are 09:00-20:00, but today's sales must not land
                // in the future, otherwise the dashboard counts money that has
                // not been taken yet.
                $latestHour = $date->isToday() ? min(20, (int) now()->format('G')) : 20;
                $stamp = $date->copy()->setTime(
                    random_int(9, max(9, $latestHour)),
                    random_int(0, 59),
                );

                if ($date->isToday() && $stamp->isFuture()) {
                    $stamp = now()->subMinutes(random_int(1, 90));
                }

                try {
                    $order = $orders->checkout(
                        ['items' => $lines],
                        [
                            'payment_method' => $method->value,
                            'paid_amount' => 0,
                            'note' => null,
                        ],
                        $cashier,
                    );
                } catch (InsufficientStockException) {
                    // Ran out of stock mid-demo; skip that basket.
                    continue;
                }

                // Backdate the sale so the dashboard and reports have a trend.
                $order->forceFill(['created_at' => $stamp, 'updated_at' => $stamp])->save();
                $order->items()->update(['created_at' => $stamp, 'updated_at' => $stamp]);

                InventoryMovement::query()
                    ->where('reference_type', $order->getMorphClass())
                    ->where('reference_id', $order->getKey())
                    ->update(['created_at' => $stamp, 'updated_at' => $stamp]);

                AuditLog::query()
                    ->where('auditable_type', $order->getMorphClass())
                    ->where('auditable_id', $order->getKey())
                    ->update(['created_at' => $stamp, 'updated_at' => $stamp]);
            }
        }
    }

    /**
     * Draw a number of distinct products, weighted by modelled daily demand.
     *
     * Uses exponential-race sampling: each product gets `weight = daily demand`
     * repeated as many "tickets" as its share warrants, and a draw picks a
     * ticket and finds its owner. A corned beef selling four a day therefore
     * turns up roughly four times as often as a detergent selling half a unit.
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function weightedPick($products, int $count)
    {
        $tickets = [];

        foreach ($products as $product) {
            $weight = CatalogSeeder::dailyDemandFor($product->name);

            // Four tickets per unit of demand: enough resolution to tell 0.3 from
            // 0.4 apart, and only a couple of hundred tickets in total.
            $ticketsForProduct = max(1, (int) round($weight * 4));

            for ($i = 0; $i < $ticketsForProduct; $i++) {
                $tickets[] = $product;
            }
        }

        if ($tickets === []) {
            return $products->take($count);
        }

        $picked = [];

        for ($i = 0; $i < $count; $i++) {
            $picked[] = $tickets[array_rand($tickets)];
        }

        return collect($picked)->unique('id')->values();
    }
}
