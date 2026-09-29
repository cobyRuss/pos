<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Support\DateFormat;
use App\Support\SqlDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(): View
    {
        return view('reports.index', [
            'range' => $this->range(request()),
        ]);
    }

    public function sales(Request $request): View|StreamedResponse
    {
        $filters = $this->validateRange($request);
        [$from, $to] = $this->resolveRange($filters);

        $base = $this->salesBaseQuery($filters, $from, $to);

        // Aggregates need their own select: adding them to the listing query would
        // mix SUM()/COUNT() with orders.* and MySQL rejects that without a GROUP BY.
        $totals = (clone $base)
            ->selectRaw('COALESCE(SUM(total), 0) as gross, COALESCE(SUM(refunded_amount), 0) as refunded, COALESCE(SUM(discount_amount), 0) as discounts, COALESCE(SUM(tax_amount), 0) as tax, COUNT(*) as orders')
            ->first();

        $query = (clone $base)->with('user')->withCount('items');

        if ($request->boolean('export')) {
            return $this->exportSales($query, $from, $to);
        }

        return view('reports.sales', [
            'orders' => (clone $query)->latest()->paginate(20)->withQueryString(),
            'totals' => [
                'orders' => (int) $totals->orders,
                'gross' => (float) $totals->gross,
                'refunded' => (float) $totals->refunded,
                'discounts' => (float) $totals->discounts,
                'tax' => (float) $totals->tax,
                'net' => round((float) $totals->gross - (float) $totals->refunded, 2),
                'average' => (int) $totals->orders > 0 ? round((float) $totals->gross / (int) $totals->orders, 2) : 0.0,
            ],
            'profit' => $this->profitSummary($filters, $from, $to, (float) $totals->refunded),
            'byDay' => $this->groupByDay($from, $to),
            'byMethod' => $this->groupByPaymentMethod($from, $to),
            'topProducts' => $this->topProducts($from, $to),
            'filters' => $filters,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Cost of goods, gross profit and margin for the selected range.
     *
     * Revenue is taken from the line totals, so it is net of discounts and
     * excludes tax (which is a liability, not income), and refunded units are
     * removed from both the revenue and the buying cost.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, float>
     */
    private function profitSummary(array $filters, Carbon $from, Carbon $to, float $refunded): array
    {
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$from, $to]);

        if (($filters['status'] ?? null) === 'cancelled') {
            $query->where('orders.status', OrderStatus::Cancelled->value);
        } elseif (! empty($filters['status'])) {
            $query->where('orders.status', $filters['status']);
        } else {
            $query->where('orders.status', '!=', OrderStatus::Cancelled->value);
        }

        if (! empty($filters['payment_method'])) {
            $query->where('orders.payment_method', $filters['payment_method']);
        }

        $row = $query
            ->selectRaw('COALESCE(SUM(order_items.line_total), 0) as line_gross')
            ->selectRaw('COALESCE(SUM(order_items.unit_cost * (order_items.quantity - order_items.refunded_quantity)), 0) as cost')
            ->selectRaw('COALESCE(SUM(order_items.unit_cost * order_items.refunded_quantity), 0) as refunded_cost')
            ->selectRaw('COALESCE(SUM(order_items.quantity - order_items.refunded_quantity), 0) as units')
            ->first();

        $revenue = round((float) $row->line_gross - $refunded, 2);
        $cost = round((float) $row->cost, 2);
        $profit = round($revenue - $cost, 2);

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'refunded_cost' => round((float) $row->refunded_cost, 2),
            'profit' => $profit,
            'margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0.0,
            'units' => (int) $row->units,
        ];
    }

    public function revenue(Request $request): View|StreamedResponse
    {
        $filters = $this->validateRange($request);
        [$from, $to] = $this->resolveRange($filters);

        if ($request->boolean('export')) {
            return $this->exportRevenue($from, $to);
        }

        return view('reports.revenue', [
            'byDay' => $this->groupByDay($from, $to),
            'byMethod' => $this->groupByPaymentMethod($from, $to),
            'byCashier' => $this->groupByCashier($from, $to),
            'totals' => $this->revenueTotals($from, $to),
            'comparison' => $this->periodComparison($from, $to),
            'filters' => $filters,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function inventory(Request $request): View|StreamedResponse
    {
        $filters = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'status' => ['nullable', 'in:low,out,ok,expiring,expired'],
        ]);

        $query = Product::query()->with(['category', 'batches']);

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        match ($filters['status'] ?? null) {
            'low' => $query->lowStock(),
            'out' => $query->where('stock', '<=', 0),
            'ok' => $query->whereColumn('stock', '>', 'low_stock_threshold'),
            'expiring' => $query->expiringStock(),
            'expired' => $query->expiredStock(),
            default => null,
        };

        if ($request->boolean('export')) {
            return $this->exportInventory($query->orderBy('category_id')->orderBy('name')->get(), $filters);
        }

        $rows = $query->orderBy('stock')->orderBy('name')->paginate(20)->withQueryString();

        return view('reports.inventory', [
            'products' => $rows,
            'categories' => Category::orderBy('name')->get(),
            'filters' => $filters,
            'summary' => $this->inventorySummary(),
            'byCategory' => $this->inventoryByCategory(),
            'expiringCount' => Product::expiringStock()->count(),
            'expiredCount' => Product::expiredStock()->count(),
            'warningDays' => ProductBatch::EXPIRY_WARNING_DAYS,
        ]);
    }

    private function validateRange(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'in:'.implode(',', OrderStatus::values())],
            'payment_method' => ['nullable', Rule::in(PaymentMethod::values())],
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(array $filters): array
    {
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : Carbon::today()->subDays(29)->startOfDay();
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : Carbon::today()->endOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    /**
     * Filtered order query without eager loads or extra selects, so callers can
     * safely layer on aggregates or a listing.
     */
    private function salesBaseQuery(array $filters, Carbon $from, Carbon $to): Builder
    {
        $query = Order::query();

        if (($filters['status'] ?? null) === 'cancelled') {
            $query->where('status', OrderStatus::Cancelled->value);
        } elseif (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', '!=', OrderStatus::Cancelled->value);
        }

        if (! empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        return $query->whereBetween('created_at', [$from, $to]);
    }

    private function groupByDay(Carbon $from, Carbon $to)
    {
        $rows = Order::query()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$from, $to])
            ->select([
                'day' => DB::raw(SqlDate::day().' as day'),
                'orders' => DB::raw('COUNT(*) as orders'),
                'gross' => DB::raw('COALESCE(SUM(total), 0) as gross'),
                'refunded' => DB::raw('COALESCE(SUM(refunded_amount), 0) as refunded'),
            ])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $out = [];

        for ($cursor = $from->copy()->startOfDay(); $cursor->lessThanOrEqualTo($to); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);

            $out[] = [
                'day' => $key,
                'orders' => (int) ($row->orders ?? 0),
                'gross' => (float) ($row->gross ?? 0),
                'refunded' => (float) ($row->refunded ?? 0),
                'net' => round((float) ($row->gross ?? 0) - (float) ($row->refunded ?? 0), 2),
            ];
        }

        return $out;
    }

    private function groupByPaymentMethod(Carbon $from, Carbon $to)
    {
        $rows = Order::query()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$from, $to])
            ->select([
                'payment_method',
                'orders' => DB::raw('COUNT(*) as orders'),
                'gross' => DB::raw('COALESCE(SUM(total), 0) as gross'),
            ])
            ->groupBy('payment_method')
            ->get();

        $grand = (float) $rows->sum('gross');

        return $rows->map(fn ($row) => [
            'method' => $row->payment_method,
            'label' => $row->payment_method->label(),
            'orders' => (int) $row->orders,
            'gross' => (float) $row->gross,
            'share' => $grand > 0 ? round((float) $row->gross / $grand * 100, 1) : 0.0,
        ])->sortByDesc('gross')->values();
    }

    private function groupByCashier(Carbon $from, Carbon $to)
    {
        return Order::query()
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.created_at', [$from, $to])
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->groupBy('users.id', 'users.name')
            ->select([
                'users.name as name',
                'orders' => DB::raw('COUNT(*) as orders'),
                'gross' => DB::raw('COALESCE(SUM(orders.total), 0) as gross'),
                'refunded' => DB::raw('COALESCE(SUM(orders.refunded_amount), 0) as refunded'),
            ])
            ->orderByDesc('gross')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'orders' => (int) $row->orders,
                'gross' => (float) $row->gross,
                'refunded' => (float) $row->refunded,
                'net' => round((float) $row->gross - (float) $row->refunded, 2),
            ]);
    }

    private function topProducts(Carbon $from, Carbon $to, int $limit = 15)
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->select([
                'order_items.product_id',
                'order_items.product_name as name',
                DB::raw('COALESCE(SUM(order_items.quantity - order_items.refunded_quantity), 0) as units'),
                DB::raw('COALESCE(SUM(order_items.line_total), 0) as revenue'),
                DB::raw('COALESCE(SUM(order_items.unit_cost * (order_items.quantity - order_items.refunded_quantity)), 0) as cost'),
                DB::raw('COALESCE(SUM(order_items.unit_cost * order_items.quantity), 0) / NULLIF(SUM(order_items.quantity), 0) as buying_price'),
                DB::raw('COALESCE(SUM(order_items.line_total), 0) / NULLIF(SUM(order_items.quantity), 0) as selling_price'),
            ])
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $row->buying_price = (float) $row->buying_price;
                $row->selling_price = (float) $row->selling_price;
                $row->cost = round((float) $row->cost, 2);
                $row->profit = round((float) $row->revenue - (float) $row->cost, 2);
                $row->margin = $row->revenue > 0
                    ? round(($row->profit / (float) $row->revenue) * 100, 1)
                    : 0.0;

                return $row;
            });
    }

    private function revenueTotals(Carbon $from, Carbon $to): array
    {
        $row = Order::query()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(total),0) as gross, COALESCE(SUM(tax_amount),0) as tax, COALESCE(SUM(discount_amount),0) as discounts, COALESCE(SUM(subtotal),0) as subtotal, COUNT(*) as orders, COALESCE(SUM(change_amount),0) as `change`')
            ->first();

        $refunds = (float) Refund::query()->whereBetween('created_at', [$from, $to])->sum('amount');
        $cancelled = (int) Order::query()
            ->where('status', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        return [
            'subtotal' => (float) $row->subtotal,
            'discounts' => (float) $row->discounts,
            'tax' => (float) $row->tax,
            'gross' => (float) $row->gross,
            'refunds' => $refunds,
            'cancelled_orders' => $cancelled,
            'net' => round((float) $row->gross - $refunds, 2),
            'change_given' => (float) $row->change,
            'orders' => (int) $row->orders,
        ];
    }

    private function periodComparison(Carbon $from, Carbon $to): array
    {
        $span = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($span - 1);

        $sum = fn (Carbon $a, Carbon $b) => (float) Order::query()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$a, $b])
            ->sum('total');

        $count = fn (Carbon $a, Carbon $b) => (int) Order::query()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$a, $b])
            ->count();

        $current = $sum($from, $to);
        $previous = $sum($prevFrom, $prevTo);
        $currentOrders = $count($from, $to);
        $previousOrders = $count($prevFrom, $prevTo);

        $pct = fn (float $now, float $before) => $before > 0
            ? round(($now - $before) / $before * 100, 1)
            : ($now > 0 ? 100.0 : 0.0);

        return [
            'current' => $current,
            'previous' => $previous,
            'change' => $pct($current, $previous),
            'current_orders' => $currentOrders,
            'previous_orders' => $previousOrders,
            'orders_change' => $pct((float) $currentOrders, (float) $previousOrders),
            'previous_label' => DateFormat::dayShort($prevFrom).' - '.DateFormat::date($prevTo),
        ];
    }

    /**
     * Cash movement and refund exposure, per cashier.
     *
     * This is the report the store actually runs on. Shifts here are ad hoc and
     * nobody opens a till session, so there is no counted-drawer figure to compare
     * against - instead it shows what each cashier's takings *should* have been,
     * and how much of it left again as refunds. A cashier whose refund column
     * keeps creeping up, or who is repeatedly filing integrity-risk reasons, is
     * visible in one glance and can be checked against the CCTV timestamp.
     */
    public function refunds(Request $request): View|StreamedResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'flagged' => ['nullable', 'in:all,flagged,unreviewed'],
        ]);

        [$from, $to] = $this->resolveRange($filters);

        if ($request->boolean('export')) {
            return $this->exportRefundReconciliation($this->refundExposureByCashier($filters, $from, $to), $from, $to);
        }

        $byCashier = $this->refundExposureByCashier($filters, $from, $to);

        return view('reports.refunds', [
            'byCashier' => $byCashier,
            'byDay' => $this->refundExposureByDay($from, $to),
            'byReason' => $this->refundExposureByReason($from, $to),
            'flagged' => Refund::query()
                ->with(['user', 'order'])
                ->where('review_required', true)
                ->whereBetween('refunded_at', [$from, $to])
                ->latest('refunded_at')
                ->limit(25)
                ->get(),
            'totals' => $this->refundExposureTotals($byCashier),
            'cashiers' => User::query()->where('role', UserRole::Staff->value)->orderBy('name')->get(),
            'threshold' => Setting::refundReviewThreshold(),
            'dailyLimit' => Setting::refundDailyLimit(),
            'filters' => $filters,
            'from' => $from,
            'to' => $to,
            'range' => $this->range($request),
        ]);
    }

    /**
     * Per-cashier takings against per-cashier refunds.
     *
     * Sales and refunds are aggregated separately and then joined in PHP: they
     * live in different tables with no useful key between them other than the
     * user, and two grouped queries are far easier to reason about than one
     * three-way join that has to get the date filters right on both sides.
     *
     * @return Collection<int, object>
     */
    private function refundExposureByCashier(array $filters, Carbon $from, Carbon $to)
    {
        $sales = Order::query()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$from, $to])
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as orders, COALESCE(SUM(total), 0) as gross')
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total ELSE 0 END), 0) as cash_sales")
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_method = 'gcash' THEN total ELSE 0 END), 0) as gcash_sales")
            ->get()
            ->keyBy('user_id');

        $refunds = Refund::query()
            ->whereBetween('refunded_at', [$from, $to])
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when(($filters['flagged'] ?? 'all') === 'flagged', fn ($q) => $q->where('review_required', true))
            ->when(($filters['flagged'] ?? 'all') === 'unreviewed', fn ($q) => $q->outstanding())
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as refunds')
            ->selectRaw('COALESCE(SUM(amount), 0) as refunded')
            ->selectRaw('COALESCE(SUM(tax_amount), 0) as tax_returned')
            ->selectRaw("COALESCE(SUM(CASE WHEN method = 'cash' THEN amount ELSE 0 END), 0) as cash_out")
            ->selectRaw('COALESCE(SUM(CASE WHEN review_required THEN 1 ELSE 0 END), 0) as flagged_count')
            ->get()
            ->keyBy('user_id');

        $names = User::query()
            ->whereIn('id', $sales->keys()->merge($refunds->keys())->filter()->unique())
            ->pluck('name', 'id');

        return $sales->keys()->merge($refunds->keys())->filter()->unique()
            ->map(function (int $userId) use ($sales, $refunds, $names) {
                $sale = $sales->get($userId);
                $refund = $refunds->get($userId);

                $gross = (float) ($sale->gross ?? 0);
                $cashSales = (float) ($sale->cash_sales ?? 0);
                $cashOut = (float) ($refund->cash_out ?? 0);

                return (object) [
                    'user_id' => $userId,
                    'name' => $names[$userId] ?? ('User #'.$userId),
                    'orders' => (int) ($sale->orders ?? 0),
                    'gross' => $gross,
                    'cash_sales' => $cashSales,
                    'gcash_sales' => (float) ($sale->gcash_sales ?? 0),
                    'refunds' => (int) ($refund->refunds ?? 0),
                    'refunded' => (float) ($refund->refunded ?? 0),
                    'tax_returned' => (float) ($refund->tax_returned ?? 0),
                    'cash_out' => $cashOut,
                    'flagged_count' => (int) ($refund->flagged_count ?? 0),
                    // What should be sitting in the drawer for this cashier.
                    'expected_cash' => round($cashSales - $cashOut, 2),
                    // Refunds as a slice of what this cashier took. The single
                    // number that trends upward when someone starts skimming.
                    'refund_rate' => $gross > 0 ? round((float) ($refund->refunded ?? 0) / $gross * 100, 2) : 0.0,
                ];
            })
            ->sortByDesc(fn ($row) => $row->refunded)
            ->values();
    }

    /**
     * @return Collection<int, object>
     */
    private function refundExposureByDay(Carbon $from, Carbon $to)
    {
        // Grouped by the `day` alias rather than the expression: passing
        // SqlDate::day() to groupBy() would get the whole thing wrapped in
        // backticks and rejected as a column name on MySQL.
        return Refund::query()
            ->whereBetween('refunded_at', [$from, $to])
            ->selectRaw(SqlDate::day('refunded_at').' as day, COUNT(*) as refunds, COALESCE(SUM(amount), 0) as refunded')
            ->selectRaw('COALESCE(SUM(CASE WHEN review_required THEN 1 ELSE 0 END), 0) as flagged_count')
            ->groupBy('day')
            ->orderBy('day')
            ->get();
    }

    /**
     * @return Collection<int, object>
     */
    private function refundExposureByReason(Carbon $from, Carbon $to)
    {
        // Aliased to `code`, not `reason_code`: the model casts that attribute to
        // the RefundReason enum, and grouping into a column of the same name hands
        // the view an enum where it is expecting a raw value.
        return Refund::query()
            ->whereBetween('refunded_at', [$from, $to])
            ->groupBy('reason_code')
            ->selectRaw('reason_code as code, COUNT(*) as refunds, COALESCE(SUM(amount), 0) as refunded')
            ->orderByDesc('refunded')
            ->get();
    }

    /**
     * @param  Collection<int, object>  $byCashier
     * @return array<string, float|int>
     */
    private function refundExposureTotals($byCashier): array
    {
        $gross = round((float) $byCashier->sum('gross'), 2);
        $refunded = round((float) $byCashier->sum('refunded'), 2);

        return [
            'cashiers' => $byCashier->count(),
            'orders' => (int) $byCashier->sum('orders'),
            'gross' => $gross,
            'cash_sales' => round((float) $byCashier->sum('cash_sales'), 2),
            'refunds' => (int) $byCashier->sum('refunds'),
            'refunded' => $refunded,
            'refund_rate' => $gross > 0 ? round($refunded / $gross * 100, 2) : 0.0,
            'cash_out' => round((float) $byCashier->sum('cash_out'), 2),
            'expected_cash' => round((float) $byCashier->sum('expected_cash'), 2),
            'flagged_count' => (int) $byCashier->sum('flagged_count'),
        ];
    }

    private function exportRefundReconciliation($byCashier, Carbon $from, Carbon $to): StreamedResponse
    {
        $filename = 'refund-reconciliation-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($byCashier) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Cashier', 'Orders', 'Gross sales', 'Cash sales', 'GCash sales',
                'Refunds', 'Refunded total', 'Tax returned', 'Cash paid out',
                'Expected cash', 'Refund rate %', 'Flagged refunds',
            ]);

            foreach ($byCashier as $row) {
                fputcsv($handle, [
                    $row->name,
                    $row->orders,
                    number_format($row->gross, 2, '.', ''),
                    number_format($row->cash_sales, 2, '.', ''),
                    number_format($row->gcash_sales, 2, '.', ''),
                    $row->refunds,
                    number_format($row->refunded, 2, '.', ''),
                    number_format($row->tax_returned, 2, '.', ''),
                    number_format($row->cash_out, 2, '.', ''),
                    number_format($row->expected_cash, 2, '.', ''),
                    number_format($row->refund_rate, 2, '.', ''),
                    $row->flagged_count,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function inventorySummary(): array
    {
        $row = Product::query()
            ->selectRaw('COUNT(*) as products, COALESCE(SUM(stock),0) as units, COALESCE(SUM(stock * cost_price),0) as cost_value, COALESCE(SUM(stock * selling_price),0) as retail_value')
            ->first();

        return [
            'products' => (int) $row->products,
            'units' => (int) $row->units,
            'cost_value' => (float) $row->cost_value,
            'retail_value' => (float) $row->retail_value,
            'low' => Product::lowStock()->count(),
            'out' => Product::where('stock', '<=', 0)->count(),
        ];
    }

    private function inventoryByCategory()
    {
        return Category::query()
            ->withSum('products as units', 'stock')
            ->withCount('products')
            ->orderByDesc('units')
            ->get()
            ->map(fn (Category $category) => [
                'name' => $category->name,
                'products' => (int) $category->products_count,
                'units' => (int) $category->units,
            ]);
    }

    private function range(Request $request): string
    {
        $from = $request->input('from');
        $to = $request->input('to');

        return sprintf('%s to %s', $from ?: '30 days ago', $to ?: 'today');
    }

    private function exportSales($query, Carbon $from, Carbon $to): StreamedResponse
    {
        $filename = 'sales-report-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Order #', 'Date', 'Status', 'Cashier', 'Payment', 'Items', 'Units sold', 'Revenue (ex tax)', 'Buying cost', 'Profit', 'Discount', 'Tax', 'Total', 'Refunded', 'Net']);

            foreach ($query->with(['user', 'items'])->cursor() as $order) {
                $cost = round($order->items->sum(
                    fn ($item) => (float) $item->unit_cost * $item->refundable_quantity
                ), 2);
                $revenue = round($order->items->sum(
                    fn ($item) => (float) $item->line_total - ((float) $item->unit_cost * $item->refunded_quantity)
                ), 2);

                fputcsv($handle, [
                    $order->order_number,
                    $order->created_at->format('Y-m-d H:i'),
                    $order->status->label(),
                    $order->cashier_name,
                    $order->payment_method->label(),
                    $order->items_count,
                    $order->items->sum('quantity'),
                    number_format($revenue, 2, '.', ''),
                    number_format($cost, 2, '.', ''),
                    number_format(round($revenue - $cost, 2), 2, '.', ''),
                    $order->discount_amount,
                    $order->tax_amount,
                    $order->total,
                    $order->refunded_amount,
                    $order->net_total,
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function exportRevenue(Carbon $from, Carbon $to): StreamedResponse
    {
        $filename = 'revenue-report-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($from, $to) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Date', 'Orders', 'Gross', 'Refunded', 'Net']);

            foreach ($this->groupByDay($from, $to) as $row) {
                fputcsv($handle, [$row['day'], $row['orders'], number_format($row['gross'], 2, '.', ''), number_format($row['refunded'], 2, '.', ''), number_format($row['net'], 2, '.', '')]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['Payment Method', 'Orders', 'Gross', 'Share %']);

            foreach ($this->groupByPaymentMethod($from, $to) as $row) {
                fputcsv($handle, [$row['label'], $row['orders'], number_format($row['gross'], 2, '.', ''), $row['share']]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['Cashier', 'Orders', 'Gross', 'Refunded', 'Net']);

            foreach ($this->groupByCashier($from, $to) as $row) {
                fputcsv($handle, [$row['name'], $row['orders'], number_format($row['gross'], 2, '.', ''), number_format($row['refunded'], 2, '.', ''), number_format($row['net'], 2, '.', '')]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Inventory CSV, with the per-delivery expiry dates so the sheet can be
     * used to plan write-offs.
     */
    private function exportInventory($products, array $filters): StreamedResponse
    {
        $filename = 'inventory-report-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($products) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Product', 'Category', 'Unit', 'Stock', 'Sellable', 'Lots',
                'Next Expiry', 'Expiry Status',
                'Low Stock Threshold', 'Cost', 'Price', 'Stock Value', 'Status',
            ]);

            foreach ($products as $product) {
                $expiryStatus = $product->expiryStatus();
                $nextExpiry = $product->nextExpiryDate();

                fputcsv($handle, [
                    $product->name,
                    $product->category?->name ?? 'Uncategorised',
                    $product->unit,
                    $product->stock,
                    $product->sellableStock(),
                    $product->batches->count(),
                    $nextExpiry?->toDateString() ?? '',
                    match ($expiryStatus) {
                        'expired' => 'Expired',
                        'expiring' => 'Expiring soon',
                        'ok' => 'Good',
                        default => 'No expiry',
                    },
                    $product->low_stock_threshold,
                    number_format((float) $product->cost_price, 2, '.', ''),
                    number_format((float) $product->selling_price, 2, '.', ''),
                    number_format((float) $product->stock_value, 2, '.', ''),
                    $product->isOutOfStock() ? 'Out of stock' : ($product->isLowStock() ? 'Low' : 'OK'),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
