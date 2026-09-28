<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Refund;
use App\Models\User;
use App\Support\SqlDate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $period = $request->validate([
            'period' => ['nullable', 'in:today,week,month,year'],
        ])['period'] ?? 'week';

        [$start, $end, $label] = $this->resolvePeriod($period);

        $base = fn () => Order::query()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$start, $end]);

        $todayStart = Carbon::today();
        $todayEnd = Carbon::today()->endOfDay();
        $weekStart = Carbon::today()->startOfWeek();
        $monthStart = Carbon::today()->startOfMonth();

        $sum = fn ($from, $to) => (float) Order::query()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$from, $to])
            ->sum('total');

        return view('dashboard.index', [
            'period' => $period,
            'periodLabel' => $label,

            'totalSales' => $sum($start, $end),
            'orderCount' => (clone $base())->count(),
            'averageOrder' => (clone $base())->avg('total') ?: 0,
            'unitsSold' => (int) DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', '!=', OrderStatus::Cancelled->value)
                ->whereBetween('orders.created_at', [$start, $end])
                ->sum('order_items.quantity'),

            'dailySales' => $sum($todayStart, $todayEnd),
            'weeklySales' => $sum($weekStart, Carbon::today()->endOfDay()),
            'monthlySales' => $sum($monthStart, Carbon::today()->endOfDay()),

            'todayOrders' => (clone Order::query())
                ->whereBetween('created_at', [$todayStart, $todayEnd])
                ->count(),

            'refundsThisPeriod' => (int) Refund::query()
                ->whereBetween('created_at', [$start, $end])
                ->count(),

            'chart' => $this->buildChart($period, $start, $end),
            'bestSellers' => $this->bestSellers($start, $end),
            'profit' => $this->profitTracking($start, $end),
            'lowStockProducts' => Product::lowStock()->with('category')->orderBy('stock')->limit(10)->get(),
            'outOfStockCount' => Product::where('stock', '<=', 0)->count(),
            'lowStockCount' => Product::lowStock()->count(),
            'expiredCount' => Product::expiredStock()->count(),
            'expiringCount' => Product::expiringStock()->count(),
            'expiryWarningDays' => ProductBatch::EXPIRY_WARNING_DAYS,
            'expiringProducts' => Product::query()
                ->whereHas('batches', fn ($q) => $q->expiringWithin())
                ->with('category')
                ->orderBy('stock')
                ->limit(10)
                ->get(),
            'totalProducts' => Product::where('is_active', true)->count(),

            'recentOrders' => Order::query()
                ->with('user')
                ->latest()
                ->limit(10)
                ->get(),

            'topCashiers' => User::query()
                ->where('role', UserRole::Staff)
                ->withCount('orders')
                ->orderByDesc('orders_count')
                ->limit(5)
                ->get(),
        ]);
    }

    /**
     * @return array{labels: array<int, string>, revenue: array<int, float>, orders: array<int, int>}
     */
    private function buildChart(string $period, Carbon $start, Carbon $end): array
    {
        [$groupBy, $labelFormat, $bucket] = match ($period) {
            'today' => ['%H:00', 'H:i', 'hour'],
            'month' => ['%d', 'M j', 'day'],
            'year' => ['%Y-%m', 'M Y', 'month'],
            default => ['%Y-%m-%d', 'M j', 'day'],
        };

        $rows = Order::query()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$start, $end])
            ->select([
                'bucket' => DB::raw(SqlDate::bucket($groupBy).' as bucket'),
                'revenue' => DB::raw('COALESCE(SUM(total), 0) as revenue'),
                'orders' => DB::raw('COUNT(*) as orders'),
            ])
            ->groupBy('bucket')
            ->pluck('revenue', 'bucket');

        $orderCounts = Order::query()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$start, $end])
            ->select([
                'bucket' => DB::raw(SqlDate::bucket($groupBy).' as bucket'),
                'orders' => DB::raw('COUNT(*) as orders'),
            ])
            ->groupBy('bucket')
            ->pluck('orders', 'bucket');

        $labels = [];
        $revenue = [];
        $orders = [];

        for ($cursor = $start->copy(); $cursor->lessThanOrEqualTo($end); $cursor = $this->advance($cursor, $bucket)) {
            $key = $this->bucketKey($cursor, $bucket);
            $labels[] = $cursor->format($labelFormat);
            $revenue[] = round((float) ($rows[$key] ?? 0), 2);
            $orders[] = (int) ($orderCounts[$key] ?? 0);
        }

        return ['labels' => $labels, 'revenue' => $revenue, 'orders' => $orders];
    }

    private function bucketKey(Carbon $cursor, string $bucket): string
    {
        return match ($bucket) {
            'hour' => $cursor->format('H:00'),
            'month' => $cursor->format('Y-m'),
            default => $cursor->format('Y-m-d'),
        };
    }

    private function advance(Carbon $cursor, string $bucket): Carbon
    {
        return match ($bucket) {
            'hour' => $cursor->copy()->addHour(),
            'month' => $cursor->copy()->addMonth()->startOfMonth(),
            default => $cursor->copy()->addDay(),
        };
    }

    /**
     * Cost price, selling price, quantity and profit for the dashboard.
     *
     * Two views of the same numbers:
     *  - sold: what the period actually earned, using the buying price frozen on
     *    each sold line and stripping out refunded units;
     *  - stock: profit still sitting on the shelf at today's prices.
     *
     * Both are derived live on every request, so editing a price or adjusting
     * stock is reflected the next time the dashboard loads. Negative profit is
     * carried through as a loss rather than clamped to zero.
     *
     * @return array<string, mixed>
     */
    private function profitTracking(Carbon $start, Carbon $end): array
    {
        return [
            'sold' => $this->soldProfit($start, $end),
            'stock' => $this->stockProfit(),
        ];
    }

    /**
     * Realised profit per product for the selected period.
     *
     * @return array<string, mixed>
     */
    private function soldProfit(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.created_at', [$start, $end])
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->select([
                'order_items.product_id',
                'order_items.product_name as name',
                DB::raw('COALESCE(SUM(order_items.quantity - order_items.refunded_quantity), 0) as quantity'),
                DB::raw('COALESCE(SUM(order_items.line_total), 0) as sales_gross'),
                DB::raw('COALESCE(SUM(order_items.unit_cost * (order_items.quantity - order_items.refunded_quantity)), 0) as cost'),
                DB::raw('COALESCE(SUM(order_items.unit_price * order_items.quantity), 0) / NULLIF(SUM(order_items.quantity), 0) as selling_price'),
            ])
            ->get();

        // Refunds are recorded against a line, so net them off per product here.
        $refunds = DB::table('refund_items')
            ->join('order_items', 'order_items.id', '=', 'refund_items.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.created_at', [$start, $end])
            ->groupBy('order_items.product_id')
            ->select([
                'order_items.product_id as product_id',
                DB::raw('COALESCE(SUM(refund_items.amount), 0) as amount'),
            ])
            ->pluck('amount', 'product_id');

        $lines = $rows->map(function ($row) use ($refunds) {
            $quantity = (int) $row->quantity;
            $sales = round((float) $row->sales_gross - (float) ($refunds[$row->product_id] ?? 0), 2);
            $cost = round((float) $row->cost, 2);

            return (object) [
                'product_id' => $row->product_id,
                'name' => $row->name,
                'quantity' => $quantity,
                'cost_price' => $quantity > 0 ? round($cost / $quantity, 2) : 0.0,
                'selling_price' => round((float) $row->selling_price, 2),
                'cost' => $cost,
                'sales' => $sales,
                'profit' => round($sales - $cost, 2),
            ];
        })
            ->sortByDesc('profit')
            ->values();

        return $this->profitTotals($lines);
    }

    /**
     * Profit that current stock would produce if it all sold at today's prices.
     *
     * @return array<string, mixed>
     */
    private function stockProfit(): array
    {
        $lines = Product::query()
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->get(['id', 'name', 'cost_price', 'selling_price', 'stock'])
            ->map(fn (Product $product) => (object) [
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => (int) $product->stock,
                'cost_price' => (float) $product->cost_price,
                'selling_price' => (float) $product->selling_price,
                'cost' => round((float) $product->cost_price * (int) $product->stock, 2),
                'sales' => round((float) $product->selling_price * (int) $product->stock, 2),
                'profit' => round(((float) $product->selling_price - (float) $product->cost_price) * (int) $product->stock, 2),
            ])
            ->sortByDesc('profit')
            ->values();

        return $this->profitTotals($lines);
    }

    /**
     * @param  Collection<int, object>  $lines
     * @return array<string, mixed>
     */
    private function profitTotals($lines): array
    {
        $cost = round((float) $lines->sum('cost'), 2);
        $sales = round((float) $lines->sum('sales'), 2);
        $profit = round($sales - $cost, 2);

        return [
            'lines' => $lines,
            'products' => $lines->count(),
            'quantity' => (int) $lines->sum('quantity'),
            'cost' => $cost,
            'sales' => $sales,
            'profit' => $profit,
            'margin' => $sales > 0 ? round(($profit / $sales) * 100, 1) : 0.0,
            'losses' => $lines->filter(fn ($line) => $line->profit < 0)->count(),
        ];
    }

    private function bestSellers(Carbon $start, Carbon $end)
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.created_at', [$start, $end])
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->select([
                'order_items.product_id',
                'order_items.product_name',
                DB::raw('SUM(order_items.quantity) as units'),
                DB::raw('SUM(order_items.line_total) as revenue'),
            ])
            ->orderByDesc('units')
            ->limit(10)
            ->get();
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolvePeriod(string $period): array
    {
        return match ($period) {
            'today' => [Carbon::today()->startOfDay(), Carbon::today()->endOfDay(), 'Today'],
            'month' => [Carbon::today()->startOfMonth(), Carbon::today()->endOfDay(), 'This Month'],
            'year' => [Carbon::today()->startOfYear(), Carbon::today()->endOfDay(), 'This Year'],
            default => [
                Carbon::today()->subDays(6)->startOfDay(),
                Carbon::today()->endOfDay(),
                'Last 7 Days',
            ],
        };
    }
}
