@extends('layouts.app')

@section('title', 'Inventory Report')
@section('page-title', 'Inventory Report')
@section('page-subtitle', 'Stock on hand, valuation and alerts')

@section('content')
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="{{ route('admin.reports.inventory', array_merge(request()->query(), ['export' => 1])) }}"
       class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-download me-1"></i>Export CSV
    </a>
    <a href="{{ route('admin.reports.index') }}" class="btn btn-sm btn-outline-secondary ms-auto">
        <i class="bi bi-grid me-1"></i>All reports
    </a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.reports.inventory') }}" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label for="category_id" class="form-label small fw-semibold">Category</label>
                <select class="form-select" id="category_id" name="category_id">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) ($filters['category_id'] ?? '') === (string) $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label for="status" class="form-label small fw-semibold">Stock status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All products</option>
                    @foreach (['low' => 'Low stock', 'out' => 'Out of stock', 'ok' => 'Healthy', 'expiring' => 'Expiring soon', 'expired' => 'Expired stock'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel"></i> Apply</button>
                <a href="{{ route('admin.reports.inventory') }}" class="btn btn-outline-secondary" title="Reset">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Products</div>
                <div class="stat-value">{{ number_format($summary['products']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Units on hand</div>
                <div class="stat-value">{{ number_format($summary['units']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Stock value (cost)</div>
                <div class="stat-value">{{ \App\Models\Setting::money($summary['cost_value']) }}</div>
                <div class="stat-meta text-body-secondary">retail {{ \App\Models\Setting::money($summary['retail_value']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Needs attention</div>
                <div class="stat-value">
                    <span class="text-warning">{{ $summary['low'] }}</span> /
                    <span class="text-danger">{{ $summary['out'] }}</span>
                </div>
                <div class="stat-meta text-body-secondary">low / out of stock</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <a href="{{ route('admin.reports.inventory', ['status' => 'expired']) }}"
           class="text-decoration-none {{ $expiredCount + $expiringCount === 0 ? 'd-none' : '' }}">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="stat-label">Expiry watch</div>
                    <div class="stat-value">
                        <span class="text-warning">{{ $expiringCount }}</span> /
                        <span class="text-danger">{{ $expiredCount }}</span>
                    </div>
                    <div class="stat-meta text-body-secondary">expiring / expired</div>
                </div>
            </div>
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header">Products ({{ $products->total() }})</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th class="text-center">Stock</th>
                        <th class="text-center">Sellable</th>
                        <th>Next Expiry</th>
                        <th class="text-center">Alert</th>
                        <th class="text-end">Cost</th>
                        <th class="text-end">Price</th>
                        <th class="text-end">Stock value</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($products as $product)
                        @php $expiryStatus = $product->expiryStatus(); @endphp
                        <tr class="{{ $product->isOutOfStock() || $expiryStatus === 'expired' ? 'table-danger' : ($product->isLowStock() || $expiryStatus === 'expiring' ? 'table-warning' : '') }}">
                            <td>
                                <a href="{{ route('products.show', $product) }}" class="text-decoration-none fw-semibold">
                                    {{ $product->name }}
                                </a>
                                <div class="text-body-secondary small">{{ $product->unit }}</div>
                            </td>
                            <td>{{ $product->category?->name ?? '—' }}</td>
                            <td class="text-center fw-semibold">{{ $product->stock }}</td>
                            <td class="text-center">
                                {{ $product->sellableStock() }}
                                @if ($product->expiredQuantity() > 0)
                                    <div class="small text-danger">{{ $product->expiredQuantity() }} expired</div>
                                @endif
                            </td>
                            <td>
                                @if ($product->nextExpiryDate())
                                    <span class="badge {{ match ($expiryStatus) {
                                        'expired' => 'text-bg-danger',
                                        'expiring' => 'text-bg-warning',
                                        default => 'text-bg-light',
                                    } }}">
                                        {{ \App\Support\DateFormat::date($product->nextExpiryDate()) }}
                                    </span>
                                @else
                                    <span class="text-body-secondary small">No expiry</span>
                                @endif
                            </td>
                            <td class="text-center text-body-secondary">{{ $product->low_stock_threshold }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($product->cost_price) }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($product->selling_price) }}</td>
                            <td class="text-end money fw-semibold">{{ \App\Models\Setting::money($product->stock_value) }}</td>
                            <td>
                                @if ($expiryStatus === 'expired')
                                    <span class="badge text-bg-danger">Expired</span>
                                @elseif ($product->isOutOfStock())
                                    <span class="badge text-bg-danger">Out</span>
                                @elseif ($expiryStatus === 'expiring')
                                    <span class="badge text-bg-warning">Expiring</span>
                                @elseif ($product->isLowStock())
                                    <span class="badge text-bg-warning">Low</span>
                                @else
                                    <span class="badge text-bg-success">OK</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-body-secondary py-4">No products match these filters.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if ($products->hasPages())
                <div class="card-footer">{{ $products->links() }}</div>
            @endif
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card">
            <div class="card-header">Units on hand by category</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                    <tr>
                        <th>Category</th>
                        <th class="text-center">Products</th>
                        <th class="text-center">Units</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($byCategory as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td class="text-center">{{ $row['products'] }}</td>
                            <td class="text-center fw-semibold">{{ number_format($row['units']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-body-secondary py-4">No categories yet.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
