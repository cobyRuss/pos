<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SalesAnalytics;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Date-ranged sales reporting with a CSV export.
 *
 * The export is a stream rather than a buffered string so a year of orders
 * does not have to fit in memory, and it is generated from exactly the same
 * query as the screen, so a downloaded file can never disagree with the page
 * the user was looking at when they clicked.
 */
class ReportController extends Controller
{
    public function __construct(private readonly SalesAnalytics $analytics) {}

    /**
     * Rolling report for today, the last 7 days or the last 30 days.
     */
    public function index(Request $request): View|StreamedResponse
    {
        $range = SalesAnalytics::resolveRange($request->query('range'));
        $filters = $this->validateFilters($request, requireDates: false);

        $from = $this->analytics->startOf($range);
        $to = $this->analytics->endOf();

        $orders = $this->orders(array_merge($filters, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]));

        if ($request->query('format') === 'csv') {
            return $this->export($orders, 'sales-'.$range.'-'.now()->format('Ymd').'.csv');
        }

        return view('admin.reports.index', [
            'range' => $range,
            'ranges' => SalesAnalytics::ranges(),
            'filters' => $filters,
            'orders' => $orders,
            'totals' => $this->totals($orders),
            'trend' => $this->analytics->dailyTrend($range),
            'byPayment' => $this->analytics->byPaymentMethod($range),
            'byStaff' => $this->analytics->byStaff($range),
            'bestSellers' => $this->analytics->bestSellers($range),
            'lowStock' => Product::query()->active()->lowStock()->orderBy('stock')->limit(20)->get(),
            'inventoryValue' => Money::toCents(
                Product::query()->active()->selectRaw('COALESCE(SUM(stock * cost), 0) AS value')->value('value')
            ),
            'currency' => Setting::currency(),
        ]);
    }

    /**
     * Sales report for an explicit custom date range.
     */
    public function sales(Request $request): View|StreamedResponse
    {
        $filters = $this->validateFilters($request, requireDates: true);

        $orders = $this->orders($filters);

        if ($request->query('format') === 'csv') {
            return $this->export(
                $orders,
                sprintf('sales-%s-to-%s.csv', $filters['from'], $filters['to']),
            );
        }

        $byDay = $orders
            ->groupBy(fn (Order $order) => $order->created_at->toDateString())
            ->map(fn (Collection $group, string $day): array => [
                'label' => Carbon::parse($day)->format('D j M Y'),
                'orders' => $group->count(),
                'gross' => (int) $group->sum(fn (Order $o) => Money::toCents($o->total)),
                'refunds' => (int) $group->sum(fn (Order $o) => Money::toCents($o->refunded_total)),
            ])
            ->sortKeysDesc()
            ->values();

        return view('admin.reports.sales', [
            'filters' => $filters,
            'orders' => $orders,
            'byDay' => $byDay,
            'totals' => $this->totals($orders),
            'currency' => Setting::currency(),
        ]);
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return array{orders: int, gross: int, refunds: int, net: int, tendered: int, average: int}
     */
    private function totals(Collection $orders): array
    {
        $gross = (int) $orders->sum(fn (Order $order) => Money::toCents($order->total));
        $refunds = (int) $orders->sum(fn (Order $order) => Money::toCents($order->refunded_total));
        $tendered = (int) $orders->sum(fn (Order $order) => Money::toCents($order->amount_paid));
        $count = $orders->count();

        return [
            'orders' => $count,
            'gross' => $gross,
            'refunds' => $refunds,
            'net' => max(0, $gross - $refunds),
            'tendered' => $tendered,
            'average' => $count > 0 ? (int) round($gross / $count) : 0,
        ];
    }

    /**
     * A clean, print-optimised table for the same report.
     *
     * The plan allows for a printable HTML table where no PDF package exists.
     * It reads the identical query as the screen, so the paper copy and the
     * screen can never disagree.
     */
    public function print(Request $request, string $report = 'sales'): View|RedirectResponse
    {
        if ($report === 'inventory') {
            return $this->printInventory();
        }

        $filters = $this->validateFilters($request, requireDates: true);
        $orders = $this->orders($filters);
        $totals = $this->totals($orders);
        $currency = Setting::currency();
        $money = fn (int $cents) => $currency.Money::fromCents($cents);

        return view('reports.printable', [
            'title' => 'Sales report',
            'subtitle' => sprintf(
                '%s to %s · %d order(s)',
                Carbon::parse($filters['from'])->format('d M Y'),
                Carbon::parse($filters['to'])->format('d M Y'),
                $totals['orders'],
            ),
            'backUrl' => route('admin.reports.sales', $filters),
            'backLabel' => 'sales report',
            'businessName' => Setting::get(Setting::BUSINESS_NAME),
            'columns' => [
                ['label' => 'Order', 'render' => fn (Order $o) => e($o->order_number)],
                ['label' => 'Date', 'render' => fn (Order $o) => e($o->created_at->format('d M Y H:i'))],
                ['label' => 'Cashier', 'render' => fn (Order $o) => e($o->user?->name ?? '—')],
                ['label' => 'Payment', 'render' => fn (Order $o) => e(ucfirst($o->payment_method))],
                ['label' => 'Items', 'numeric' => true, 'render' => fn (Order $o) => (int) ($o->items_sum_quantity ?? 0)],
                ['label' => 'Total', 'numeric' => true, 'render' => fn (Order $o) => e($o->total)],
                [
                    'label' => 'Refunded',
                    'numeric' => true,
                    'render' => fn (Order $o) => (float) $o->refunded_total > 0 ? e($o->refunded_total) : '—',
                ],
                ['label' => 'Net', 'numeric' => true, 'render' => fn (Order $o) => e(number_format((float) $o->net_total, 2))],
            ],
            'rows' => $orders,
            'totals' => [
                'Gross' => $money($totals['gross']),
                'Refunded' => $money($totals['refunds']),
                'Tendered' => $money($totals['tendered']),
                'Average order' => $money($totals['average']),
                'Net revenue' => $money($totals['net']),
            ],
        ]);
    }

    /**
     * Stock on hand, valued at cost.
     */
    private function printInventory(): View
    {
        $products = Product::query()->with('category')->orderBy('name')->get();
        $currency = Setting::currency();
        $money = fn (int $cents) => $currency.Money::fromCents($cents);

        $value = (int) $products->sum(
            fn (Product $p) => (int) round((float) $p->stock * (float) $p->cost * 100)
        );

        return view('reports.printable', [
            'title' => 'Inventory report',
            'subtitle' => sprintf('%d product(s) · %d unit(s) on hand', $products->count(), (int) $products->sum('stock')),
            'backUrl' => route('admin.reports.index'),
            'backLabel' => 'reports',
            'businessName' => Setting::get(Setting::BUSINESS_NAME),
            'columns' => [
                ['label' => 'SKU', 'render' => fn (Product $p) => e($p->sku)],
                ['label' => 'Product', 'render' => fn (Product $p) => e($p->name)],
                ['label' => 'Category', 'render' => fn (Product $p) => e($p->category?->name ?? 'Uncategorised')],
                ['label' => 'Cost', 'numeric' => true, 'render' => fn (Product $p) => e($p->cost)],
                ['label' => 'Price', 'numeric' => true, 'render' => fn (Product $p) => e($p->price)],
                [
                    'label' => 'Stock',
                    'numeric' => true,
                    'render' => fn (Product $p) => (int) $p->stock < 0
                        ? '<span style="color:#b91c1c">'.(int) $p->stock.'</span>'
                        : (int) $p->stock,
                ],
                [
                    'label' => 'Value',
                    'numeric' => true,
                    'render' => fn (Product $p) => $money((int) round((float) $p->stock * (float) $p->cost * 100)),
                ],
            ],
            'rows' => $products,
            'totals' => ['Stock value at cost' => $money($value)],
        ]);
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    private function export(Collection $orders, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($orders): void {
            $handle = fopen('php://output', 'wb');

            // A BOM so spreadsheet apps detect UTF-8 and keep accented names.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Order', 'Date', 'Time', 'Cashier', 'Customer', 'Payment', 'Reference',
                'Subtotal', 'Discount', 'Tax', 'Total', 'Refunded', 'Net', 'Status',
            ], ',', '"', '\\');

            foreach ($orders as $order) {
                fputcsv($handle, [
                    $order->order_number,
                    $order->created_at->format('Y-m-d'),
                    $order->created_at->format('H:i'),
                    $order->user?->name ?? '',
                    $order->walkin_customer_name ?? '',
                    $order->payment_method,
                    $order->reference_no ?? '',
                    $order->subtotal,
                    $order->discount_amount,
                    $order->tax_amount,
                    $order->total,
                    $order->refunded_total,
                    number_format((float) $order->net_total, 2, '.', ''),
                    $order->status,
                ], ',', '"', '\\');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return Collection<int, Order>
     */
    private function orders(array $filters): Collection
    {
        return Order::query()
            ->with('user')
            ->withSum('items', 'quantity')
            ->when($filters['status'] ?? null, fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['payment_method'] ?? null, fn ($query) => $query->where('payment_method', $filters['payment_method']))
            ->when($filters['user_id'] ?? null, fn ($query) => $query->where('user_id', $filters['user_id']))
            ->when($filters['from'] ?? null, fn ($query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'] ?? null, fn ($query) => $query->whereDate('created_at', '<=', $filters['to']))
            ->latest('created_at')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request, bool $requireDates = false): array
    {
        return $request->validate([
            'range' => ['nullable', Rule::in(array_keys(SalesAnalytics::ranges()))],
            'from' => [$requireDates ? 'required' : 'nullable', 'date'],
            'to' => [$requireDates ? 'required' : 'nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in([Order::STATUS_COMPLETED, Order::STATUS_CANCELLED])],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'digital'])],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
    }
}
