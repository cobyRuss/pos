@extends('layouts.app')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')
@section('page-subtitle', $periodLabel)

@section('content')
<div class="d-flex flex-wrap gap-2 mb-3">
    <div class="btn-group btn-group-sm" role="group" aria-label="Reporting period">
        @foreach (['today' => 'Today', 'week' => 'Last 7 Days', 'month' => 'This Month', 'year' => 'This Year'] as $value => $label)
            <a href="{{ route('admin.dashboard', ['period' => $value]) }}"
               class="btn {{ $period === $value ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $label }}</a>
        @endforeach
    </div>

    @if (auth()->user()->isStaff())
        <a href="{{ route('pos.index') }}" class="btn btn-sm btn-success ms-auto">
            <i class="bi bi-cash-coin me-1"></i>New Sale
        </a>
    @endif
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-graph-up-arrow"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Sales ({{ $periodLabel }})</div>
                    <div class="stat-value">{{ \App\Models\Setting::money($totalSales) }}</div>
                    <div class="stat-meta text-body-secondary">{{ $orderCount }} order(s)</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-receipt"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Average Order</div>
                    <div class="stat-value">{{ \App\Models\Setting::money($averageOrder) }}</div>
                    <div class="stat-meta text-body-secondary">{{ $unitsSold }} unit(s) sold</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icon bg-info bg-opacity-10 text-info"><i class="bi bi-box-seam"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Active Products</div>
                    <div class="stat-value">{{ $totalProducts }}</div>
                    <div class="stat-meta text-body-secondary">
                        <span class="text-danger">{{ $outOfStockCount }} out</span> /
                        <span class="text-warning">{{ $lowStockCount }} low</span>
                        @if ($expiredCount + $expiringCount > 0)
                            /
                            <span class="text-warning">{{ $expiredCount + $expiringCount }} expiring</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-arrow-counterclockwise"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Refunds ({{ $periodLabel }})</div>
                    <div class="stat-value">{{ $refundsThisPeriod }}</div>
                    <div class="stat-meta text-body-secondary">
                        <a href="{{ route('admin.refunds.index') }}">View refunds</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Today</div>
                <div class="stat-value">{{ \App\Models\Setting::money($dailySales) }}</div>
                <div class="stat-meta text-body-secondary">{{ $todayOrders }} order(s) today</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">This Week</div>
                <div class="stat-value">{{ \App\Models\Setting::money($weeklySales) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">This Month</div>
                <div class="stat-value">{{ \App\Models\Setting::money($monthlySales) }}</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header">Sales Trend &mdash; {{ $periodLabel }}</div>
            <div class="card-body">
                <div style="height: 320px;">
                    <canvas id="salesChart"
                            data-currency="{{ \App\Models\Setting::currency() }}"
                            data-labels="{{ \Illuminate\Support\Js::from($chart['labels']) }}"
                            data-revenue="{{ \Illuminate\Support\Js::from($chart['revenue']) }}"
                            data-orders="{{ \Illuminate\Support\Js::from($chart['orders']) }}"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header">Best Sellers</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th class="text-center">Units</th>
                        <th class="text-end">Revenue</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($bestSellers as $row)
                        <tr>
                            <td class="text-truncate" style="max-width: 180px;">
                                @if ($row->product_id)
                                    <a href="{{ route('products.show', $row->product_id) }}" class="text-decoration-none">
                                        {{ $row->product_name }}
                                    </a>
                                @else
                                    {{ $row->product_name }}
                                @endif
                            </td>
                            <td class="text-center">{{ (int) $row->units }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($row->revenue) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-body-secondary py-4">No sales in this period.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icon bg-secondary bg-opacity-10 text-secondary"><i class="bi bi-bag"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Total Cost ({{ $periodLabel }})</div>
                    <div class="stat-value">{{ \App\Models\Setting::money($profit['sold']['cost']) }}</div>
                    <div class="stat-meta text-body-secondary">buying cost of units sold</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-cash-coin"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Total Sales ({{ $periodLabel }})</div>
                    <div class="stat-value">{{ \App\Models\Setting::money($profit['sold']['sales']) }}</div>
                    <div class="stat-meta text-body-secondary">{{ $profit['sold']['quantity'] }} unit(s) sold</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icon {{ $profit['sold']['profit'] >= 0 ? 'bg-success bg-opacity-10 text-success' : 'bg-danger bg-opacity-10 text-danger' }}">
                    <i class="bi {{ $profit['sold']['profit'] >= 0 ? 'bi-graph-up-arrow' : 'bi-graph-down-arrow' }}"></i>
                </span>
                <div class="min-w-0">
                    <div class="stat-label">Total Profit ({{ $periodLabel }})</div>
                    <div class="stat-value {{ $profit['sold']['profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ \App\Models\Setting::money($profit['sold']['profit']) }}
                    </div>
                    <div class="stat-meta text-body-secondary">
                        @if ($profit['sold']['losses'] > 0)
                            <span class="text-danger">{{ $profit['sold']['losses'] }} product(s) at a loss</span>
                        @else
                            every product profitable
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icon bg-info bg-opacity-10 text-info"><i class="bi bi-percent"></i></span>
                <div class="min-w-0">
                    <div class="stat-label">Profit Margin ({{ $periodLabel }})</div>
                    <div class="stat-value {{ $profit['sold']['margin'] >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ number_format($profit['sold']['margin'], 1) }}%
                    </div>
                    <div class="stat-meta text-body-secondary">profit over sales</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center">
                <span>Profit Tracking &mdash; sold in {{ $periodLabel }}</span>
                <span class="ms-auto small text-body-secondary">
                    Profit = (Selling Price &minus; Cost Price) &times; Quantity
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th class="text-end">Cost Price</th>
                        <th class="text-end">Selling Price</th>
                        <th class="text-center">Quantity</th>
                        <th class="text-end">Total Profit</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($profit['sold']['lines']->take(10) as $line)
                        <tr class="{{ $line->profit < 0 ? 'table-danger' : '' }}">
                            <td class="text-truncate" style="max-width: 200px;">
                                @if ($line->product_id)
                                    <a href="{{ route('products.show', $line->product_id) }}" class="text-decoration-none fw-semibold">
                                        {{ $line->name }}
                                    </a>
                                @else
                                    {{ $line->name }}
                                @endif
                            </td>
                            <td class="text-end money text-body-secondary">{{ \App\Models\Setting::money($line->cost_price) }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($line->selling_price) }}</td>
                            <td class="text-center">{{ $line->quantity }}</td>
                            <td class="text-end money fw-semibold {{ $line->profit < 0 ? 'text-danger' : 'text-success' }}">
                                {{ \App\Models\Setting::money($line->profit) }}
                                @if ($line->profit < 0)
                                    <span class="badge text-bg-danger ms-1">Loss</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-4">No sales in this period.</td>
                        </tr>
                    @endforelse
                    </tbody>
                    @if ($profit['sold']['lines']->isNotEmpty())
                        <tfoot>
                        <tr class="table-light fw-semibold">
                            <td>Total ({{ $profit['sold']['products'] }} product(s))</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($profit['sold']['cost']) }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($profit['sold']['sales']) }}</td>
                            <td class="text-center">{{ $profit['sold']['quantity'] }}</td>
                            <td class="text-end money {{ $profit['sold']['profit'] < 0 ? 'text-danger' : 'text-success' }}">
                                {{ \App\Models\Setting::money($profit['sold']['profit']) }}
                            </td>
                        </tr>
                        <tr class="table-light">
                            <td colspan="4" class="text-end text-body-secondary small">Profit margin</td>
                            <td class="text-end small {{ $profit['sold']['margin'] < 0 ? 'text-danger' : 'text-success' }}">
                                {{ number_format($profit['sold']['margin'], 1) }}%
                            </td>
                        </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header">Profit Tracking &mdash; current stock</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th class="text-end">Cost</th>
                        <th class="text-end">Sell</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Profit</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($profit['stock']['lines']->take(10) as $line)
                        <tr class="{{ $line->profit < 0 ? 'table-danger' : '' }}">
                            <td class="text-truncate" style="max-width: 150px;">
                                <a href="{{ route('products.show', $line->product_id) }}" class="text-decoration-none fw-semibold">
                                    {{ $line->name }}
                                </a>
                            </td>
                            <td class="text-end money text-body-secondary">{{ \App\Models\Setting::money($line->cost_price) }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($line->selling_price) }}</td>
                            <td class="text-center">{{ $line->quantity }}</td>
                            <td class="text-end money fw-semibold {{ $line->profit < 0 ? 'text-danger' : 'text-success' }}">
                                {{ \App\Models\Setting::money($line->profit) }}
                                @if ($line->profit < 0)
                                    <span class="badge text-bg-danger ms-1">Loss</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-4">No stock on hand.</td>
                        </tr>
                    @endforelse
                    </tbody>
                    @if ($profit['stock']['lines']->isNotEmpty())
                        <tfoot>
                        <tr class="table-light fw-semibold">
                            <td>Total ({{ $profit['stock']['products'] }} product(s))</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($profit['stock']['cost']) }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($profit['stock']['sales']) }}</td>
                            <td class="text-center">{{ $profit['stock']['quantity'] }}</td>
                            <td class="text-end money {{ $profit['stock']['profit'] < 0 ? 'text-danger' : 'text-success' }}">
                                {{ \App\Models\Setting::money($profit['stock']['profit']) }}
                            </td>
                        </tr>
                        <tr class="table-light">
                            <td colspan="4" class="text-end text-body-secondary small">Profit margin</td>
                            <td class="text-end small {{ $profit['stock']['margin'] < 0 ? 'text-danger' : 'text-success' }}">
                                {{ number_format($profit['stock']['margin'], 1) }}%
                            </td>
                        </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            @if ($profit['stock']['losses'] > 0)
                <div class="card-footer small text-danger">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    {{ $profit['stock']['losses'] }} product(s) are priced below cost &mdash; selling them at today's price loses money.
                </div>
            @endif
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header">Recent Orders</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Date</th>
                        <th>Cashier</th>
                        <th>Payment</th>
                        <th class="text-end">Total</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($recentOrders as $order)
                        <tr>
                            <td>
                                <a href="{{ route('orders.show', $order) }}" class="text-decoration-none fw-semibold">
                                    {{ $order->order_number }}
                                </a>
                            </td>
                            <td class="text-nowrap">{{ $order->created_at->diffForHumans() }}</td>
                            <td>{{ $order->cashier_name }}</td>
                            <td class="small">{{ $order->payment_method->label() }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($order->total) }}</td>
                            <td><span class="badge {{ $order->status->badgeClass() }}">{{ $order->status->label() }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-body-secondary py-4">No orders yet.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-body-tertiary">
                <a href="{{ route('orders.index') }}" class="small">View all orders <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center">
                Low Stock Alerts
                @if ($lowStockCount + $outOfStockCount > 0)
                    <span class="badge text-bg-danger ms-2">{{ $lowStockCount + $outOfStockCount }}</span>
                @endif
                <a href="{{ route('inventory.index', ['status' => 'low']) }}" class="btn btn-sm btn-outline-secondary ms-auto">
                    Manage
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                    @forelse ($lowStockProducts as $product)
                        <tr class="{{ $product->isOutOfStock() ? 'table-danger' : 'table-warning' }}">
                            <td>
                                <a href="{{ route('products.show', $product) }}" class="text-decoration-none fw-semibold">
                                    {{ $product->name }}
                                </a>
                            </td>
                            <td class="text-center">
                                <span class="badge {{ $product->isOutOfStock() ? 'text-bg-danger' : 'text-bg-warning' }}">
                                    {{ $product->stock }}
                                </span>
                            </td>
                            <td class="text-end small text-body-secondary">alert &le; {{ $product->low_stock_threshold }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="text-center text-body-secondary py-4">
                                <i class="bi bi-check-circle text-success"></i> All stock levels are healthy.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex align-items-center">
                Expiry Watch
                @if ($expiredCount + $expiringCount > 0)
                    <span class="badge text-bg-warning ms-2">{{ $expiredCount + $expiringCount }}</span>
                @endif
                <a href="{{ route('inventory.index', ['status' => 'expiring']) }}" class="btn btn-sm btn-outline-secondary ms-auto">
                    Manage
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                    @forelse ($expiringProducts as $product)
                        @php $status = $product->expiryStatus(); @endphp
                        <tr class="{{ $status === 'expired' ? 'table-danger' : 'table-warning' }}">
                            <td>
                                <a href="{{ route('products.show', $product) }}" class="text-decoration-none fw-semibold">
                                    {{ $product->name }}
                                </a>
                            </td>
                            <td class="text-center">
                                <span class="badge {{ $status === 'expired' ? 'text-bg-danger' : 'text-bg-warning' }}">
                                    {{ $status === 'expired' ? 'Expired' : 'Soon' }}
                                </span>
                            </td>
                            <td class="text-end small text-body-secondary">
                                {{ $product->nextExpiryDate()?->format('M j, Y') ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="text-center text-body-secondary py-4">
                                <i class="bi bi-check-circle text-success"></i>
                                Nothing expires within {{ $expiryWarningDays }} days.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($expiredCount > 0)
                <div class="card-footer bg-body-tertiary small text-danger">
                    <i class="bi bi-exclamation-octagon-fill me-1"></i>
                    {{ $expiredCount }} product(s) still hold stock past its expiry date.
                </div>
            @endif
        </div>

        <div class="card">
            <div class="card-header">Top Cashiers (all time)</div>
            <div class="list-group list-group-flush">
                @forelse ($topCashiers as $cashier)
                    <div class="list-group-item d-flex align-items-center gap-2">
                        <span class="avatar avatar-sm">{{ $cashier->initials() }}</span>
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate">{{ $cashier->name }}</div>
                            <div class="text-body-secondary small">{{ $cashier->orders_count }} order(s)</div>
                        </div>
                    </div>
                @empty
                    <div class="list-group-item text-center text-body-secondary py-4">No staff activity yet.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    'use strict';

    const canvas = document.getElementById('salesChart');
    if (!canvas || typeof Chart === 'undefined') return;

    const money = canvas.dataset.currency;
    const labels = JSON.parse(canvas.dataset.labels);
    const revenue = JSON.parse(canvas.dataset.revenue);
    const orders = JSON.parse(canvas.dataset.orders);

    new Chart(canvas, {
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'line',
                    label: 'Revenue',
                    data: revenue,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.15)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    yAxisID: 'y',
                },
                {
                    type: 'bar',
                    label: 'Orders',
                    data: orders,
                    backgroundColor: 'rgba(25, 135, 84, 0.55)',
                    borderRadius: 4,
                    yAxisID: 'y1',
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ctx.dataset.label === 'Revenue'
                            ? ` Revenue: ${money}${ctx.parsed.y.toFixed(2)}`
                            : ` Orders: ${ctx.parsed.y}`,
                    },
                },
            },
            scales: {
                y: {
                    position: 'left',
                    beginAtZero: true,
                    ticks: {
                        callback: (value) => money + Number(value).toFixed(0),
                    },
                },
                y1: {
                    position: 'right',
                    beginAtZero: true,
                    grid: { drawOnChartArea: false },
                    ticks: { precision: 0 },
                },
            },
        },
    });
})();
</script>
@endpush
