@extends('layouts.app')

@section('title', 'Inventory')
@section('page-title', 'Inventory')
@section('page-subtitle', $canAdjust ? 'Stock levels, expiry dates and low-stock alerts' : 'Stock levels (read-only)')

@section('content')
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-x-octagon"></i></span>
                <div>
                    <div class="stat-label">Out of Stock</div>
                    <div class="stat-value">{{ $outOfStockCount }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-exclamation-triangle"></i></span>
                <div>
                    <div class="stat-label">Low Stock</div>
                    <div class="stat-value">{{ $lowStockCount }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <a href="{{ route('inventory.index', ['status' => 'expiring']) }}"
           class="text-decoration-none {{ $expiredCount === 0 && $expiringCount === 0 ? 'd-none' : '' }}">
            <div class="card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon bg-secondary bg-opacity-10 text-secondary"><i class="bi bi-hourglass-split"></i></span>
                    <div>
                        <div class="stat-label">Expiring ≤{{ $warningDays }}d</div>
                        <div class="stat-value">
                            {{ $expiredCount + $expiringCount }}
                            @if ($expiredCount > 0)
                                <span class="badge text-bg-danger">{{ $expiredCount }} expired</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-cash-stack"></i></span>
                <div>
                    <div class="stat-label">Stock Value (Cost)</div>
                    <div class="stat-value">{{ \App\Models\Setting::money($stockValue) }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

@if ($expiredCount > 0)
    <div class="alert alert-danger d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-octagon-fill"></i>
        <div>
            <strong>{{ $expiredCount }} product(s) hold stock past its expiry date.</strong>
            Expired units cannot be sold.
        </div>
        <a href="{{ route('inventory.index', ['status' => 'expired']) }}" class="btn btn-sm btn-danger ms-auto">Show Expired</a>
    </div>
@elseif ($expiringCount > 0)
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-clock-fill"></i>
        <div>
            <strong>{{ $expiringCount }} product(s) expire within {{ $warningDays }} days.</strong>
            Sales draw from the soonest date first.
        </div>
        <a href="{{ route('inventory.index', ['status' => 'expiring']) }}" class="btn btn-sm btn-warning ms-auto">Show Expiring</a>
    </div>
@endif

@if ($lowStockCount > 0 || $outOfStockCount > 0)
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
            <strong>{{ $outOfStockCount + $lowStockCount }} product(s)</strong> need restocking.
        </div>
        <a href="{{ route('inventory.index', ['status' => 'low']) }}" class="btn btn-sm btn-warning ms-auto">Show Low Stock</a>
    </div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('inventory.index') }}" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label for="q" class="form-label small fw-semibold">Search</label>
                <input type="search" class="form-control" id="q" name="q" value="{{ $filters['q'] ?? '' }}"
                       placeholder="Product name or reason…">
            </div>
            <div class="col-md-3">
                <label for="category_id" class="form-label small fw-semibold">Category</label>
                <select class="form-select" id="category_id" name="category_id">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(($filters['category_id'] ?? '') == $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label small fw-semibold">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    @foreach ([
                        'low' => 'Low stock',
                        'out' => 'Out of stock',
                        'ok' => 'Healthy',
                        'expiring' => 'Expiring soon',
                        'expired' => 'Expired stock',
                    ] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center">
        Stock Levels
        @if ($canAdjust)
            <a href="{{ route('admin.inventory.movements') }}" class="btn btn-sm btn-outline-secondary ms-auto">
                <i class="bi bi-arrow-left-right me-1"></i>Movement History
            </a>
        @endif
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Product</th>
                <th>Category</th>
                <th class="text-center">Stock</th>
                <th class="text-center">Lots</th>
                <th>Next Expiry</th>
                <th class="text-center">Alert At</th>
                <th class="text-end">Stock Value</th>
                <th>Status</th>
                @if ($canAdjust)
                    <th class="text-end">Action</th>
                @endif
            </tr>
            </thead>
            <tbody>
            @php $columnCount = $canAdjust ? 9 : 8; @endphp
            @forelse ($products as $product)
                @php
                    $expiryStatus = $product->expiryStatus();
                    $soonestBatch = $product->batches
                        ->filter(fn ($b) => $b->hasExpiryDate() && $b->quantity > 0)
                        ->sortBy(fn ($b) => $b->expiry_date->timestamp)
                        ->first();
                    $nextExpiry = $soonestBatch?->expiry_date;
                @endphp
                <tr class="{{ $product->isOutOfStock() ? 'table-danger' : ($product->isLowStock() ? 'table-warning' : '') }}">
                    <td>
                        <a href="{{ route('products.show', $product) }}" class="text-decoration-none fw-semibold">
                            {{ $product->name }}
                        </a>
                    </td>
                    <td>{{ $product->category?->name ?? '—' }}</td>
                    <td class="text-center">
                        <span class="badge fs-6 {{ $product->isOutOfStock() ? 'text-bg-danger' : ($product->isLowStock() ? 'text-bg-warning' : 'text-bg-success') }}">
                            {{ $product->stock }}
                        </span>
                    </td>
                    <td class="text-center text-body-secondary">
                        {{ $product->batches->count() }}
                        @if ($product->batches->count() > 1)
                            <i class="bi bi-layers" title="Split across several deliveries"></i>
                        @endif
                    </td>
                    <td>
                        @if ($soonestBatch === null)
                            <span class="text-body-secondary small">No expiry</span>
                        @else
                            <span class="badge {{ match ($expiryStatus) {
                                'expired' => 'text-bg-danger',
                                'expiring' => 'text-bg-warning',
                                default => 'text-bg-light',
                            } }}">
                                {{ $nextExpiry->format('M j, Y') }}
                            </span>
                            <div class="small {{ $expiryStatus === 'expired' ? 'text-danger' : 'text-body-secondary' }}">
                                {{ $soonestBatch->expiryLabel() }}
                            </div>
                        @endif
                    </td>
                    <td class="text-center text-body-secondary">{{ $product->low_stock_threshold }}</td>
                    <td class="text-end money">{{ \App\Models\Setting::money($product->stock_value) }}</td>
                    <td>
                        @if ($product->isOutOfStock())
                            <span class="badge text-bg-danger">Out of stock</span>
                        @elseif ($expiryStatus === 'expired')
                            <span class="badge text-bg-danger">Expired</span>
                        @elseif ($expiryStatus === 'expiring')
                            <span class="badge text-bg-warning">Expiring</span>
                        @elseif ($product->isLowStock())
                            <span class="badge text-bg-warning">Low</span>
                        @else
                            <span class="badge text-bg-success">OK</span>
                        @endif
                    </td>
                    @if ($canAdjust)
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                @if ($expiryStatus !== 'none')
                                    <a href="{{ route('admin.batches.index', $product) }}" class="btn btn-sm btn-outline-secondary"
                                       title="Manage delivery lots and expiry dates">
                                        <i class="bi bi-layers"></i>
                                    </a>
                                @endif
                                <a href="{{ route('admin.inventory.create', $product) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-sliders"></i> Adjust
                                </a>
                            </div>
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $columnCount }}" class="text-center text-body-secondary py-4">
                        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                        No products match your filters.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($products->hasPages())
        <div class="card-footer">{{ $products->links() }}</div>
    @endif

    @unless ($canAdjust)
        <div class="card-footer bg-body-tertiary small text-body-secondary">
            <i class="bi bi-info-circle me-1"></i>
            Stock levels are read-only for your role. Contact an administrator to adjust inventory.
        </div>
    @endunless
</div>
@endsection
