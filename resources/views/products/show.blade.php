@extends('layouts.app')

@section('title', $product->name)
@section('page-title', $product->name)
@section('page-subtitle', $product->category?->name ?? 'Uncategorised')

@section('content')
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="{{ route('products.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Products
    </a>
    @if (auth()->user()->isAdmin())
        <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-primary btn-sm">
            <i class="bi bi-pencil me-1"></i>Edit Product
        </a>
        <a href="{{ route('admin.inventory.create', $product) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-boxes me-1"></i>Adjust Stock
        </a>
        <a href="{{ route('admin.batches.index', $product) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-layers me-1"></i>Batches &amp; Expiry
        </a>
    @endif
</div>

@if ($expiredCount > 0)
    <div class="alert alert-danger d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-octagon-fill"></i>
        <div>
            <strong>{{ $expiredCount }} lot(s) past the expiry date.</strong>
            Those units are counted in stock but cannot be sold.
        </div>
    </div>
@elseif ($expiringCount > 0)
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-clock-fill"></i>
        <div>
            <strong>{{ $expiringCount }} lot(s) expire soon.</strong>
            Sales draw from the soonest date first.
        </div>
    </div>
@endif

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Selling Price</div>
                <div class="stat-value">{{ \App\Models\Setting::money($product->selling_price) }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Cost Price</div>
                <div class="stat-value">{{ \App\Models\Setting::money($product->cost_price) }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Margin / Unit</div>
                <div class="stat-value {{ $product->margin >= 0 ? 'text-success' : 'text-danger' }}">
                    {{ \App\Models\Setting::money($product->margin) }}
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="stat-label">Units Sold</div>
                <div class="stat-value">{{ $totalsSold }}</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card mb-3">
            @if ($product->hasImage())
                <img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}"
                     class="card-img-top" style="aspect-ratio:4/3;object-fit:cover;">
            @endif
            <div class="card-header">Details</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-body-secondary fw-normal">Category</dt>
                    <dd class="col-7">{{ $product->category?->name ?? '—' }}</dd>

                    <dt class="col-5 text-body-secondary fw-normal">Unit</dt>
                    <dd class="col-7">{{ $product->unit }}</dd>

                    <dt class="col-5 text-body-secondary fw-normal">Stock</dt>
                    <dd class="col-7">
                        <span class="badge {{ $product->isOutOfStock() ? 'text-bg-danger' : ($product->isLowStock() ? 'text-bg-warning' : 'text-bg-success') }}">
                            {{ $product->stock }}
                        </span>
                    </dd>

                    @if ($product->stock > $product->sellableStock())
                        <dt class="col-5 text-body-secondary fw-normal">Sellable</dt>
                        <dd class="col-7">
                            <span class="badge text-bg-warning">{{ $product->sellableStock() }}</span>
                            <span class="small text-body-secondary">{{ $product->expiredQuantity() }} expired</span>
                        </dd>
                    @endif

                    <dt class="col-5 text-body-secondary fw-normal">Next expiry</dt>
                    <dd class="col-7">
                        @if ($soonestBatch)
                            <span class="badge {{ match ($product->expiryStatus()) {
                                'expired' => 'text-bg-danger',
                                'expiring' => 'text-bg-warning',
                                default => 'text-bg-light',
                            } }}">
                                {{ $soonestBatch->expiry_date->format('M j, Y') }}
                            </span>
                            <div class="small text-body-secondary">{{ $soonestBatch->expiryLabel() }}</div>
                        @else
                            <span class="text-body-secondary">No expiry</span>
                        @endif
                    </dd>

                    <dt class="col-5 text-body-secondary fw-normal">Delivery lots</dt>
                    <dd class="col-7">{{ $product->batches->count() }}</dd>

                    <dt class="col-5 text-body-secondary fw-normal">Low stock at</dt>
                    <dd class="col-7">{{ $product->low_stock_threshold }}</dd>

                    <dt class="col-5 text-body-secondary fw-normal">Stock value</dt>
                    <dd class="col-7">{{ \App\Models\Setting::money($product->stock_value) }}</dd>

                    <dt class="col-5 text-body-secondary fw-normal">Sales revenue</dt>
                    <dd class="col-7">{{ \App\Models\Setting::money($revenue) }}</dd>

                    <dt class="col-5 text-body-secondary fw-normal">Status</dt>
                    <dd class="col-7">
                        @if ($product->is_active)
                            <span class="badge text-bg-success">Active</span>
                        @else
                            <span class="badge text-bg-secondary">Inactive</span>
                        @endif
                    </dd>
                </dl>
            </div>
            @if ($product->description)
                <div class="card-footer bg-body-tertiary small">{{ $product->description }}</div>
            @endif
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Recent Sales</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Date</th>
                        <th>Cashier</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Line Total</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($sales as $item)
                        <tr>
                            <td>
                                <a href="{{ route('orders.show', $item->order) }}" class="text-decoration-none fw-semibold">
                                    {{ $item->order->order_number }}
                                </a>
                            </td>
                            <td class="text-nowrap">{{ $item->order->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $item->order->cashier_name }}</td>
                            <td class="text-center">{{ $item->quantity }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($item->line_total) }}</td>
                            <td><span class="badge {{ $item->order->status->badgeClass() }}">{{ $item->order->status->label() }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-body-secondary py-4">No sales recorded for this product yet.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">Recent Stock Movements</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th class="text-center">Change</th>
                        <th class="text-center">Before</th>
                        <th class="text-center">After</th>
                        <th>Reason</th>
                        <th>By</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($movements as $movement)
                        <tr>
                            <td class="text-nowrap">{{ $movement->created_at->format('d/m/Y H:i') }}</td>
                            <td><span class="badge {{ $movement->type->badgeClass() }}">{{ $movement->type->label() }}</span></td>
                            <td class="text-center money fw-semibold {{ $movement->isIncrease() ? 'text-success' : 'text-danger' }}">
                                {{ $movement->isIncrease() ? '+' : '' }}{{ $movement->quantity }}
                            </td>
                            <td class="text-center">{{ $movement->before_stock }}</td>
                            <td class="text-center">{{ $movement->after_stock }}</td>
                            <td class="small text-body-secondary">{{ $movement->reason ?? '—' }}</td>
                            <td class="small">{{ $movement->user?->name ?? 'System' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-body-secondary py-4">No stock movements recorded.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
