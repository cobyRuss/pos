@extends('layouts.app')

@section('title', 'Revenue Report')
@section('page-title', 'Revenue Report')
@section('page-subtitle', $from->format('d M Y').' — '.$to->format('d M Y'))

@section('content')
@php
    $statuses = collect(\App\Enums\OrderStatus::cases())->mapWithKeys(
        fn (\App\Enums\OrderStatus $status) => [$status->value => $status->label()]
    )->all();
@endphp
@include('reports._range', ['route' => 'admin.reports.revenue', 'statuses' => $statuses])

<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="{{ route('admin.reports.revenue', array_merge(request()->query(), ['export' => 1])) }}"
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
                <div class="stat-label">Gross revenue</div>
                <div class="stat-value">{{ \App\Models\Setting::money($totals['gross']) }}</div>
                <div class="stat-meta text-body-secondary">{{ number_format($totals['orders']) }} order(s)</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Net revenue</div>
                <div class="stat-value text-success">{{ \App\Models\Setting::money($totals['net']) }}</div>
                <div class="stat-meta text-body-secondary">after {{ \App\Models\Setting::money($totals['refunds']) }} refunded</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Tax collected</div>
                <div class="stat-value">{{ \App\Models\Setting::money($totals['tax']) }}</div>
                <div class="stat-meta text-body-secondary">
                    discounts given {{ \App\Models\Setting::money($totals['discounts']) }}
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Cancelled orders</div>
                <div class="stat-value">{{ number_format($totals['cancelled_orders']) }}</div>
                <div class="stat-meta text-body-secondary">change given {{ \App\Models\Setting::money($totals['change_given']) }}</div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3 {{ $comparison['change'] >= 0 ? 'border-success' : 'border-danger' }}">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <span class="stat-icon {{ $comparison['change'] >= 0 ? 'bg-success' : 'bg-danger' }} bg-opacity-10 text-{{ $comparison['change'] >= 0 ? 'success' : 'danger' }}">
            <i class="bi bi-graph-{{ $comparison['change'] >= 0 ? 'up' : 'down' }}-arrow"></i>
        </span>
        <div>
            <div class="stat-label">Compared with {{ $comparison['previous_label'] }}</div>
            <div class="fw-semibold">
                {{ \App\Models\Setting::money($comparison['previous']) }} &rarr;
                <span class="{{ $comparison['change'] >= 0 ? 'text-success' : 'text-danger' }}">
                    {{ \App\Models\Setting::money($comparison['current']) }}
                </span>
                <span class="badge ms-1 {{ $comparison['change'] >= 0 ? 'text-bg-success' : 'text-bg-danger' }}">
                    {{ $comparison['change'] >= 0 ? '+' : '' }}{{ number_format($comparison['change'], 1) }}%
                </span>
            </div>
        </div>
        <div class="ms-auto text-end">
            <div class="stat-label">Orders</div>
            <div class="fw-semibold">
                {{ number_format($comparison['previous_orders']) }} &rarr; {{ number_format($comparison['current_orders']) }}
                <span class="badge ms-1 {{ $comparison['orders_change'] >= 0 ? 'text-bg-success' : 'text-bg-danger' }}">
                    {{ $comparison['orders_change'] >= 0 ? '+' : '' }}{{ number_format($comparison['orders_change'], 1) }}%
                </span>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header">Net revenue by day</div>
            <div class="card-body">
                <div style="height: 280px;">
                    <canvas id="revenueChart"
                            data-currency="{{ \App\Models\Setting::currency() }}"
                            data-labels="{{ \Illuminate\Support\Js::from(collect($byDay)->pluck('day')->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('M j'))->all()) }}"
                            data-net="{{ \Illuminate\Support\Js::from(collect($byDay)->pluck('net')->map(fn ($v) => (float) $v)->all()) }}"
                            data-orders="{{ \Illuminate\Support\Js::from(collect($byDay)->pluck('orders')->map(fn ($v) => (int) $v)->all()) }}"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header">Revenue by payment method</div>
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
                    <tfoot class="table-group-divider">
                    <tr class="fw-semibold">
                        <td>Subtotal</td>
                        <td class="text-center">{{ \App\Models\Setting::money($totals['subtotal']) }}</td>
                        <td class="text-end money">Tax {{ \App\Models\Setting::money($totals['tax']) }}</td>
                        <td class="text-end"></td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Revenue by cashier</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Cashier</th>
                <th class="text-center">Orders</th>
                <th class="text-end">Gross</th>
                <th class="text-end">Refunded</th>
                <th class="text-end">Net</th>
                <th class="text-end">Avg / order</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($byCashier as $row)
                <tr>
                    <td class="fw-semibold">{{ $row['name'] }}</td>
                    <td class="text-center">{{ $row['orders'] }}</td>
                    <td class="text-end money">{{ \App\Models\Setting::money($row['gross']) }}</td>
                    <td class="text-end money text-danger">{{ \App\Models\Setting::money($row['refunded']) }}</td>
                    <td class="text-end money fw-semibold">{{ \App\Models\Setting::money($row['net']) }}</td>
                    <td class="text-end money">
                        {{ \App\Models\Setting::money($row['orders'] > 0 ? round($row['gross'] / $row['orders'], 2) : 0) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-body-secondary py-4">No sales in this period.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    'use strict';

    const canvas = document.getElementById('revenueChart');
    if (!canvas || typeof Chart === 'undefined') return;

    const money = canvas.dataset.currency;

    new Chart(canvas, {
        data: {
            labels: JSON.parse(canvas.dataset.labels),
            datasets: [
                {
                    type: 'line',
                    label: 'Net revenue',
                    data: JSON.parse(canvas.dataset.net),
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25, 135, 84, 0.12)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    yAxisID: 'y',
                },
                {
                    type: 'bar',
                    label: 'Orders',
                    data: JSON.parse(canvas.dataset.orders),
                    backgroundColor: 'rgba(13, 110, 253, 0.45)',
                    borderRadius: 3,
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
                        label: (ctx) => ctx.dataset.label === 'Net revenue'
                            ? ` Net revenue: ${money}${Number(ctx.parsed.y).toFixed(2)}`
                            : ` Orders: ${ctx.parsed.y}`,
                    },
                },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: (value) => money + Number(value).toFixed(0) },
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
