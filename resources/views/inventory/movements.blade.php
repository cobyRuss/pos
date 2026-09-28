@extends('layouts.app')

@section('title', 'Stock Movements')
@section('page-title', 'Stock Movement History')
@section('page-subtitle', 'Every stock change, with who made it and why')

@section('content')
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.inventory.movements') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="q" class="form-label small fw-semibold">Search</label>
                <input type="search" class="form-control" id="q" name="q" value="{{ $filters['q'] ?? '' }}"
                       placeholder="Product, SKU or reason…">
            </div>
            <div class="col-md-3">
                <label for="type" class="form-label small fw-semibold">Movement Type</label>
                <select class="form-select" id="type" name="type">
                    <option value="">All types</option>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
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
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">Log ({{ $movements->total() }})</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Date</th>
                <th>Product</th>
                <th>Type</th>
                <th class="text-center">Change</th>
                <th class="text-center">Before</th>
                <th class="text-center">After</th>
                <th>Lot</th>
                <th>Reason</th>
                <th>By</th>
                <th>Reference</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($movements as $movement)
                <tr>
                    <td class="text-nowrap">{{ $movement->created_at->format('d/m/Y H:i') }}</td>
                    <td>
                        @if ($movement->product)
                            <a href="{{ route('products.show', $movement->product) }}" class="text-decoration-none fw-semibold">
                                {{ $movement->product->name }}
                            </a>
                        @else
                            <span class="text-body-secondary">Deleted product</span>
                        @endif
                    </td>
                    <td><span class="badge {{ $movement->type->badgeClass() }}">{{ $movement->type->label() }}</span></td>
                    <td class="text-center money fw-semibold {{ $movement->isIncrease() ? 'text-success' : 'text-danger' }}">
                        {{ $movement->isIncrease() ? '+' : '' }}{{ $movement->quantity }}
                    </td>
                    <td class="text-center text-body-secondary">{{ $movement->before_stock }}</td>
                    <td class="text-center fw-semibold">{{ $movement->after_stock }}</td>
                    <td class="small">
                        @if ($movement->batch)
                            <span class="badge text-bg-light">{{ $movement->batch->batch_no ?: 'Unlabelled' }}</span>
                        @else
                            <span class="text-body-secondary">—</span>
                        @endif
                        {{-- Snapshotted at the time of the movement, so the entry
                             stays accurate if the lot is later re-dated. --}}
                        @if ($movement->expiry_date)
                            <div class="text-body-secondary">
                                {{ $movement->expiry_date->format('M j, Y') }}
                            </div>
                        @endif
                    </td>
                    <td class="small text-body-secondary">{{ $movement->reason ?? '—' }}</td>
                    <td class="small">{{ $movement->user?->name ?? 'System' }}</td>
                    <td class="small text-body-secondary">
                        @if ($movement->reference_type && $movement->reference_id)
                            {{ class_basename($movement->reference_type) }} #{{ $movement->reference_id }}
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="text-center text-body-secondary py-4">
                        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                        No stock movements match your filters.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($movements->hasPages())
        <div class="card-footer">{{ $movements->links() }}</div>
    @endif
</div>
@endsection
