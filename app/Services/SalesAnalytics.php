<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sales figures for the dashboard and reports.
 *
 * Revenue is always net of refunds: an order that was handed back is not money
 * the shop kept, so summing order totals alone would overstate takings on any
 * day with a return on it. Cancelled orders are excluded entirely, since the
 * sale was voided and the stock returned.
 *
 * Figures are accumulated as integer cents and only formatted at the edge, for
 * the same reason the till works that way: binary floats drift.
 */
class SalesAnalytics
{
    /**
     * The timeframes offered on the dashboard.
     *
     * @return array<string, string>
     */
    public static function ranges(): array
    {
        return [
            'day' => 'Today',
            'week' => 'Last 7 days',
            'month' => 'Last 30 days',
        ];
    }

    public static function resolveRange(?string $range): string
    {
        $range = (string) $range;

        return array_key_exists($range, self::ranges()) ? $range : 'day';
    }

    public function startOf(string $range): Carbon
    {
        return match ($range) {
            'week' => Carbon::now()->subDays(6)->startOfDay(),
            'month' => Carbon::now()->subDays(29)->startOfDay(),
            default => Carbon::now()->startOfDay(),
        };
    }

    public function endOf(): Carbon
    {
        return Carbon::now()->endOfDay();
    }

    /**
     * Headline figures for a period.
     *
     * @return array{gross: int, refunds: int, net: int, orders: int, average: int, items: int}
     */
    public function summary(string $range): array
    {
        $from = $this->startOf($range);
        $to = $this->endOf();

        $row = Order::query()
            ->completed()
            ->betweenDates($from->toDateTimeString(), $to->toDateTimeString())
            ->selectRaw('COALESCE(SUM(total), 0) AS gross')
            ->selectRaw('COALESCE(SUM(refunded_total), 0) AS refunded')
            ->selectRaw('COALESCE(SUM(amount_paid), 0) AS tendered')
            ->selectRaw('COUNT(*) AS orders')
            ->first();

        $gross = Money::toCents($row->gross ?? 0);
        $refunds = Money::toCents($row->refunded ?? 0);
        $orders = (int) ($row->orders ?? 0);

        $items = OrderItem::query()
            ->whereIn('order_id', $this->orderIds($from, $to))
            ->sum('quantity');

        return [
            'gross' => $gross,
            'refunds' => $refunds,
            'net' => max(0, $gross - $refunds),
            'tendered' => Money::toCents($row->tendered ?? 0),
            'orders' => $orders,
            'average' => $orders > 0 ? (int) round($gross / $orders) : 0,
            'items' => (int) $items,
        ];
    }

    /**
     * The same headline figures, but only for one cashier's till.
     *
     * A staff member sees their own numbers here rather than the shop's, since
     * the shop's takings are not theirs to see.
     *
     * @return array{gross: int, refunds: int, net: int, orders: int, average: int, items: int}
     */
    public function summaryForUser(int $userId, string $range): array
    {
        $from = $this->startOf($range);
        $to = $this->endOf();

        $row = Order::query()
            ->completed()
            ->forUser($userId)
            ->betweenDates($from->toDateTimeString(), $to->toDateTimeString())
            ->selectRaw('COALESCE(SUM(total), 0) AS gross')
            ->selectRaw('COALESCE(SUM(refunded_total), 0) AS refunded')
            ->selectRaw('COALESCE(SUM(amount_paid), 0) AS tendered')
            ->selectRaw('COUNT(*) AS orders')
            ->first();

        $gross = Money::toCents($row->gross ?? 0);
        $refunds = Money::toCents($row->refunded ?? 0);
        $orders = (int) ($row->orders ?? 0);

        $items = OrderItem::query()
            ->whereIn('order_id', $this->orderIds($from, $to, $userId))
            ->sum('quantity');

        return [
            'gross' => $gross,
            'refunds' => $refunds,
            'net' => max(0, $gross - $refunds),
            'tendered' => Money::toCents($row->tendered ?? 0),
            'orders' => $orders,
            'average' => $orders > 0 ? (int) round($gross / $orders) : 0,
            'items' => (int) $items,
        ];
    }

    /**
     * Sales totals for every day in the window, gaps included, so the trend
     * table always shows a continuous run of days.
     *
     * @return Collection<int, array{date: string, label: string, net: int, orders: int}>
     */
    public function dailyTrend(string $range): Collection
    {
        $from = $this->startOf($range);
        $to = $this->endOf();

        $rows = Order::query()
            ->completed()
            ->betweenDates($from->toDateTimeString(), $to->toDateTimeString())
            ->selectRaw('DATE(created_at) AS day')
            ->selectRaw('COALESCE(SUM(total), 0) AS gross')
            ->selectRaw('COALESCE(SUM(refunded_total), 0) AS refunded')
            ->selectRaw('COUNT(*) AS orders')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $days = collect();
        $cursor = $from->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($to)) {
            $row = $rows->get($cursor->toDateString());

            $gross = Money::toCents($row->gross ?? 0);
            $refunded = Money::toCents($row->refunded ?? 0);

            $days->push([
                'date' => $cursor->toDateString(),
                'label' => $cursor->format('D j M'),
                'net' => max(0, $gross - $refunded),
                'orders' => (int) ($row->orders ?? 0),
            ]);

            $cursor->addDay();
        }

        return $days;
    }

    /**
     * The products that sold the most units in the period.
     *
     * @return Collection<int, array{product: ?Product, name: string, sku: string, quantity: int, revenue: int}>
     */
    public function bestSellers(string $range, int $limit = 10): Collection
    {
        $orderIds = $this->orderIds($this->startOf($range), $this->endOf());

        if ($orderIds->isEmpty()) {
            return collect();
        }

        $rows = OrderItem::query()
            ->whereIn('order_id', $orderIds)
            // product_name is grouped so a deleted product can still be shown
            // by the name it sold under.
            ->selectRaw('product_id, product_name, sku, SUM(quantity) AS units, SUM(line_total) AS revenue')
            ->groupBy('product_id', 'product_name', 'sku')
            ->orderByDesc('units')
            ->limit($limit)
            ->get();

        $products = Product::whereIn('id', $rows->pluck('product_id')->filter()->unique())->get()->keyBy('id');

        return $rows->map(function (OrderItem $row) use ($products): array {
            $product = $row->product_id !== null ? $products->get($row->product_id) : null;

            return [
                'product' => $product,
                'name' => $row->product_name,
                'sku' => $row->sku,
                'quantity' => (int) $row->units,
                'revenue' => Money::toCents($row->revenue),
            ];
        });
    }

    /**
     * Revenue split by payment method.
     *
     * @return Collection<int, array{method: string, net: int, orders: int}>
     */
    public function byPaymentMethod(string $range): Collection
    {
        return $this->groupedTotals($range)
            ->map(fn (Order $row): array => [
                'method' => $row->payment_method,
                'net' => max(0, Money::toCents($row->gross) - Money::toCents($row->refunded)),
                'orders' => (int) $row->orders,
            ])
            ->sortByDesc('net')
            ->values();
    }

    /**
     * How each staff member is doing in the period.
     *
     * @return Collection<int, array{user: ?User, name: string, net: int, orders: int}>
     */
    public function byStaff(string $range): Collection
    {
        $rows = $this->groupedTotals($range, 'user_id')->keyBy('user_id');

        $users = User::whereIn('id', $rows->keys()->filter())->get()->keyBy('id');

        return $rows->map(function (Order $row) use ($users): array {
            $user = $users->get($row->user_id);

            return [
                'user' => $user,
                'name' => $user?->name ?? 'Unknown',
                'net' => max(0, Money::toCents($row->gross) - Money::toCents($row->refunded)),
                'orders' => (int) $row->orders,
            ];
        })
            ->sortByDesc('net')
            ->values();
    }

    /**
     * Products currently at or below their low-stock threshold, and the value
     * of stock on hand at cost.
     *
     * @return array{value: int, units: int, lowStock: Collection, outOfStock: int}
     */
    public function inventorySummary(): array
    {
        $lowStock = Product::query()
            ->active()
            ->lowStock()
            ->orderBy('stock')
            ->get();

        return [
            'value' => Money::toCents(
                Product::query()->active()->selectRaw('COALESCE(SUM(stock * cost), 0) AS value')->value('value')
            ),
            'units' => (int) Product::query()->active()->sum('stock'),
            'lowStock' => $lowStock,
            'outOfStock' => (int) $lowStock->where('stock', '<=', 0)->count(),
        ];
    }

    /**
     * Completed orders grouped by one column, with gross and refunded totals.
     *
     * @return Collection<int, Order>
     */
    private function groupedTotals(string $range, string $column = 'payment_method'): Collection
    {
        $from = $this->startOf($range);
        $to = $this->endOf();

        return Order::query()
            ->completed()
            ->betweenDates($from->toDateTimeString(), $to->toDateTimeString())
            ->selectRaw("{$column}")
            ->selectRaw('COALESCE(SUM(total), 0) AS gross')
            ->selectRaw('COALESCE(SUM(refunded_total), 0) AS refunded')
            ->selectRaw('COUNT(*) AS orders')
            ->groupBy($column)
            ->get();
    }

    /**
     * Completed, non-cancelled order ids inside the window, optionally for a
     * single cashier.
     *
     * @return Collection<int, int>
     */
    private function orderIds(Carbon $from, Carbon $to, ?int $userId = null): Collection
    {
        return Order::query()
            ->completed()
            ->when($userId !== null, fn ($query) => $query->forUser($userId))
            ->betweenDates($from->toDateTimeString(), $to->toDateTimeString())
            ->pluck('id');
    }
}
