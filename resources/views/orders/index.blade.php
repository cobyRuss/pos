@extends('layouts.app')

@section('title', 'Orders')
@section('page-title', 'Orders')
@section('page-subtitle', auth()->user()->isAdmin() && ! ($filters['mine'] ?? false)
    ? 'All sales across the store' : 'Your sales history')

@section('content')
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="stat-label">Orders</div>
                <div class="stat-value">{{ $summary['count'] }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="stat-label">Gross Sales</div>
                <div class="stat-value">{{ \App\Models\Setting::money($summary['gross']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="stat-label">Refunded</div>
                <div class="stat-value text-danger">{{ \App\Models\Setting::money($summary['refunded']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="stat-label">Net Sales</div>
                <div class="stat-value text-success">{{ \App\Models\Setting::money($summary['net']) }}</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Filters</div>
    <div class="card-body">
        <form method="GET" action="{{ route('orders.index') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="q" class="form-label small fw-semibold">Search</label>
                <input type="search" class="form-control" id="q" name="q" value="{{ $filters['q'] ?? '' }}"
                       placeholder="Order # or cashier name">
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label small fw-semibold">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="from" class="form-label small fw-semibold">From</label>
                <input type="date" class="form-control" id="from" name="from" value="{{ $filters['from'] ?? '' }}">
            </div>
            <div class="col-md-2">
                <label for="to" class="form-label small fw-semibold">To</label>
                <input type="date" class="form-control" id="to" name="to" value="{{ $filters['to'] ?? '' }}">
            </div>
            <div class="col-md-2 d-flex gap-2">
                @if (auth()->user()->isAdmin())
                    <div class="form-check mb-0 me-1 d-flex align-items-center">
                        <input class="form-check-input mt-0" type="checkbox" name="mine" id="mine" value="1"
                               @checked($filters['mine'] ?? false)>
                        <label class="form-check-label small ms-1" for="mine">Mine only</label>
                    </div>
                @endif
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">Sales ({{ $orders->total() }})</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Order #</th>
                <th>Date</th>
                <th>Cashier</th>
                <th>Status</th>
                <th>Payment</th>
                <th class="text-center">Items</th>
                <th class="text-end">Total</th>
                <th class="text-end">Refunded</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($orders as $order)
                <tr>
                    <td class="fw-semibold">
                        <a href="{{ route('orders.show', $order) }}" class="text-decoration-none">
                            {{ $order->order_number }}
                        </a>
                    </td>
                    <td class="text-nowrap">{{ $order->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $order->cashier_name }}</td>
                    <td><span class="badge {{ $order->status->badgeClass() }}">{{ $order->status->label() }}</span></td>
                    <td>{{ $order->payment_method->label() }}</td>
                    <td class="text-center">{{ $order->items_count }}</td>
                    <td class="text-end money fw-semibold">{{ \App\Models\Setting::money($order->total) }}</td>
                    <td class="text-end money">
                        @if ((float) $order->refunded_amount > 0)
                            <span class="text-danger">- {{ \App\Models\Setting::money($order->refunded_amount) }}</span>
                        @else
                            &mdash;
                        @endif
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('orders.receipt', $order) }}" class="btn btn-sm btn-outline-secondary" title="View receipt">
                            <i class="bi bi-receipt"></i>
                        </a>
                        @if (! $order->isCancelled() && $order->refundableItems->isNotEmpty())
                            <a href="{{ route('refunds.create', $order) }}" class="btn btn-sm btn-outline-warning ms-1" title="Refund">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center text-body-secondary py-4">
                        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                        No orders found.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($orders->hasPages())
        <div class="card-footer">
            {{ $orders->links() }}
        </div>
    @endif
</div>
@endsection
