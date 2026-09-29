@extends('layouts.app')

@section('title', 'Sales Report')
@section('page-title', 'Sales Report')
@section('page-subtitle', \App\Support\DateFormat::date($from).' — '.\App\Support\DateFormat::date($to))

@section('content')
@php
    $statuses = collect(\App\Enums\OrderStatus::cases())->mapWithKeys(
        fn (\App\Enums\OrderStatus $status) => [$status->value => $status->label()]
    )->all();
    $methods = \App\Enums\PaymentMethod::options();
@endphp
@include('reports._range', ['route' => 'admin.reports.sales', 'statuses' => $statuses, 'methods' => $methods])

<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="{{ route('admin.reports.sales', array_merge(request()->query(), ['export' => 1])) }}"
       class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-download me-1"></i>Export CSV
    </a>
    <a href="{{ route('admin.reports.index') }}" class="btn btn-sm btn-outline-secondary ms-auto">
        <i class="bi bi-grid me-1"></i>All reports
    </a>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Orders</div>
                <div class="stat-value">{{ number_format($totals['orders']) }}</div>
                <div class="stat-meta text-body-secondary">avg {{ \App\Models\Setting::money($totals['average']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Gross sales</div>
                <div class="stat-value">{{ \App\Models\Setting::money($totals['gross']) }}</div>
                <div class="stat-meta text-body-secondary">
                    discounts {{ \App\Models\Setting::money($totals['discounts']) }} &middot; tax {{ \App\Models\Setting::money($totals['tax']) }}
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Net sales</div>
                <div class="stat-value text-success">{{ \App\Models\Setting::money($totals['net']) }}</div>
                <div class="stat-meta text-body-secondary">
                    after {{ \App\Models\Setting::money($totals['refunded']) }} refunds
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Gross profit</div>
                <div class="stat-value {{ $profit['profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                    {{ \App\Models\Setting::money($profit['profit']) }}
                </div>
                <div class="stat-meta text-body-secondary">
                    {{ number_format($profit['margin'], 1) }}% margin on {{ \App\Models\Setting::money($profit['revenue']) }}
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Revenue (ex tax)</div>
                <div class="stat-value">{{ \App\Models\Setting::money($profit['revenue']) }}</div>
                <div class="stat-meta text-body-secondary">{{ number_format($profit['units']) }} units sold</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Buying cost</div>
                <div class="stat-value">{{ \App\Models\Setting::money($profit['cost']) }}</div>
                <div class="stat-meta text-body-secondary">cost of the units sold</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Margin per unit</div>
                <div class="stat-value">
                    {{ $profit['units'] > 0 ? \App\Models\Setting::money(round($profit['profit'] / $profit['units'], 2)) : \App\Models\Setting::money(0) }}
                </div>
                <div class="stat-meta text-body-secondary">average across all lines</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Refunded buying cost</div>
                <div class="stat-value">{{ \App\Models\Setting::money($profit['refunded_cost']) }}</div>
                <div class="stat-meta text-body-secondary">stock value returned to shelf</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header">Daily sales</div>
            <div class="card-body">
                <div style="height: 280px;">
                    <canvas id="dailyChart"
                            data-currency="{{ \App\Models\Setting::currency() }}"
                            data-labels="{{ \Illuminate\Support\Js::from(collect($byDay)->pluck('day')->map(fn ($d) => \App\Support\DateFormat::dayShort($d))->all()) }}"
                            data-gross="{{ \Illuminate\Support\Js::from(collect($byDay)->pluck('gross')->map(fn ($v) => (float) $v)->all()) }}"
                            data-net="{{ \Illuminate\Support\Js::from(collect($byDay)->pluck('net')->map(fn ($v) => (float) $v)->all()) }}"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header">Payment methods</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                    <tr>
                        <th>Method</th>
                        <th class="text-center">Orders</th>
                        <th class="text-end">Gross</th>
                        <th class="text-end">Share</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($byMethod as $row)
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td class="text-center">{{ $row['orders'] }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($row['gross']) }}</td>
                            <td class="text-end">{{ number_format($row['share'], 1) }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-body-secondary py-4">No sales in this period.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header">Orders ({{ $orders->total() }})</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Date</th>
                        <th>Cashier</th>
                        <th>Payment</th>
                        <th class="text-center">Items</th>
                        <th class="text-end">Discount</th>
                        <th class="text-end">Tax</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Net</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($orders as $order)
                        <tr class="{{ $order->isCancelled() ? 'table-danger' : '' }}">
                            <td>
                                <a href="{{ route('orders.show', $order) }}" class="text-decoration-none fw-semibold">
                                    {{ $order->order_number }}
                                </a>
                            </td>
                            <td class="text-nowrap">{{ \App\Support\DateFormat::dateTime($order->created_at) }}</td>
                            <td class="small">{{ $order->cashier_name }}</td>
                            <td class="small">{{ $order->payment_method->label() }}</td>
                            <td class="text-center">{{ $order->items_count }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($order->discount_amount) }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($order->tax_amount) }}</td>
                            <td class="text-end money fw-semibold">{{ \App\Models\Setting::money($order->total) }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($order->net_total) }}</td>
                            <td><span class="badge {{ $order->status->badgeClass() }}">{{ $order->status->label() }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-body-secondary py-4">No orders match these filters.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if ($orders->hasPages())
                <div class="card-footer">{{ $orders->links() }}</div>
            @endif
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header">Products by sales</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th class="text-center">Units</th>
                        <th class="text-end">Buy</th>
                        <th class="text-end">Sell</th>
                        <th class="text-end">Sales</th>
                        <th class="text-end">Profit</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($topProducts as $product)
                        <tr>
                            <td class="text-truncate" style="max-width: 150px;">
                                @if ($product->product_id)
                                    <a href="{{ route('products.show', $product->product_id) }}" class="text-decoration-none">
                                        {{ $product->name }}
                                    </a>
                                @else
                                    {{ $product->name }}
                                @endif
                            </td>
                            <td class="text-center">{{ (int) $product->units }}</td>
                            <td class="text-end money text-body-secondary">{{ \App\Models\Setting::money($product->buying_price) }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($product->selling_price) }}</td>
                            <td class="text-end money fw-semibold">{{ \App\Models\Setting::money($product->revenue) }}</td>
                            <td class="text-end money {{ $product->profit >= 0 ? 'text-success' : 'text-danger' }}"
                                title="{{ number_format($product->margin, 1) }}% margin">
                                {{ \App\Models\Setting::money($product->profit) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-body-secondary py-4">No sales in this period.</td>
                        </tr>
                    @endforelse
                    </tbody>
                    @if ($topProducts->isNotEmpty())
                        <tfoot>
                        <tr class="table-light fw-semibold">
                            <td colspan="4">Total</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($topProducts->sum('revenue')) }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($topProducts->sum('profit')) }}</td>
                        </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset("vendor/chart.js-4.4.1/chart.umd.min.js") }}"></script>
<script>
(function () {
    'use strict';

    const canvas = document.getElementById('dailyChart');
    if (!canvas || typeof Chart === 'undefined') return;

    const money = canvas.dataset.currency;

    new Chart(canvas, {
        data: {
            labels: JSON.parse(canvas.dataset.labels),
            datasets: [
                {
                    type: 'bar',
                    label: 'Gross',
                    data: JSON.parse(canvas.dataset.gross),
                    backgroundColor: 'rgba(13, 110, 253, 0.6)',
                    borderRadius: 3,
                },
                {
                    type: 'line',
                    label: 'Net',
                    data: JSON.parse(canvas.dataset.net),
                    borderColor: '#198754',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 2,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${money}${Number(ctx.parsed.y).toFixed(2)}`,
                    },
                },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: (value) => money + Number(value).toFixed(0) },
                },
            },
        },
    });
})();
</script>
@endpush
