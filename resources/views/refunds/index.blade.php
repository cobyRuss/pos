@extends('layouts.app')

@section('title', 'Refunds')
@section('page-title', 'Refunds')
@section('page-subtitle', $refundCount.' refund(s) totalling '.\App\Models\Setting::money($totalRefunded))

@section('content')
@if ($outstandingCount > 0)
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-exclamation-octagon"></i>
        <span>
            <strong>{{ $outstandingCount }}</strong> refund(s) above the review threshold
            {{ \App\Models\Setting::money(\App\Models\Setting::refundReviewThreshold()) }}
            are waiting for you to look at them.
        </span>
        <a href="{{ route('admin.refunds.index', ['status' => 'unreviewed']) }}" class="btn btn-sm btn-warning ms-auto">
            Review now
        </a>
    </div>
@endif

@if ($undeliveredAlerts > 0)
    <div class="alert alert-danger d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-bell-slash"></i>
        <span>
            <strong>{{ $undeliveredAlerts }}</strong> refund alert(s) never reached your phone.
            The refunds themselves are fine — this is about the notification.
        </span>
        <span class="ms-auto small text-body-secondary">
            Retry with <code>php artisan refund:retry-notifications --pending</code>
        </span>
    </div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.refunds.index') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="q" class="form-label small fw-semibold">Search</label>
                <input type="search" class="form-control" id="q" name="q" value="{{ $filters['q'] ?? '' }}"
                       placeholder="Refund #, order #, reason or cashier…">
            </div>
            <div class="col-md-2">
                <label for="from" class="form-label small fw-semibold">From</label>
                <input type="date" class="form-control" id="from" name="from" value="{{ $filters['from'] ?? '' }}">
            </div>
            <div class="col-md-2">
                <label for="to" class="form-label small fw-semibold">To</label>
                <input type="date" class="form-control" id="to" name="to" value="{{ $filters['to'] ?? '' }}">
            </div>
            <div class="col-md-2">
                <label for="reason_code" class="form-label small fw-semibold">Reason</label>
                <select class="form-select" id="reason_code" name="reason_code">
                    <option value="">All reasons</option>
                    @foreach ($reasons as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['reason_code'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label small fw-semibold">Review</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    <option value="unreviewed" @selected(($filters['status'] ?? '') === 'unreviewed')>Awaiting review</option>
                    <option value="reviewed" @selected(($filters['status'] ?? '') === 'reviewed')>Reviewed</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <span>Refund History ({{ $refunds->total() }})</span>
        <a href="{{ route('admin.reports.refunds', request()->query()) }}" class="btn btn-sm btn-outline-secondary ms-auto">
            <i class="bi bi-clipboard-data me-1"></i>Cash reconciliation
        </a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Refund #</th>
                <th>Date</th>
                <th>Order</th>
                <th class="text-center">Units</th>
                <th>Method</th>
                <th>Reason</th>
                <th>Cashier</th>
                <th class="text-end">Amount</th>
                <th>Review</th>
                <th>Alert</th>
                <th class="text-end"></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($refunds as $refund)
                <tr class="{{ $refund->review_required && ! $refund->isReviewed() ? 'table-warning' : '' }}">
                    <td class="fw-semibold text-nowrap">{{ $refund->refund_number }}</td>
                    <td class="text-nowrap">{{ $refund->refunded_at->format('d/m/Y H:i') }}</td>
                    <td>
                        <a href="{{ route('orders.show', $refund->order) }}" class="text-decoration-none">
                            {{ $refund->order->order_number }}
                        </a>
                    </td>
                    <td class="text-center">{{ $refund->items->sum('quantity') }}</td>
                    <td class="small">{{ $refund->method->label() }}</td>
                    <td class="small">
                        @if ($refund->reason_code)
                            <span class="badge {{ $refund->reason_code->badgeClass() }}">{{ $refund->reason_code->label() }}</span>
                            @if ($refund->reason)
                                <div class="text-body-secondary">{{ $refund->reason }}</div>
                            @endif
                        @else
                            <span class="text-body-secondary">{{ $refund->reason ?: 'Not recorded' }}</span>
                        @endif
                    </td>
                    <td class="small">{{ $refund->processed_by }}</td>
                    <td class="text-end money fw-semibold">{{ \App\Models\Setting::money($refund->amount) }}</td>
                    <td>
                        @if (! $refund->review_required)
                            <span class="text-body-secondary small">—</span>
                        @elseif ($refund->isReviewed())
                            <span class="badge text-bg-success" title="Reviewed by {{ $refund->reviewer?->name }}">
                                Reviewed
                            </span>
                        @else
                            <span class="badge text-bg-danger">Flagged</span>
                        @endif
                    </td>
                    <td>
                        @php $alert = $refund->notifications->firstWhere('channel', \App\Services\RefundService::ALERT_CHANNEL); @endphp
                        @if (! $alert)
                            <span class="text-body-secondary small">—</span>
                        @else
                            <span class="badge {{ $alert->status->badgeClass() }}"
                                  title="{{ $alert->error ?? 'Delivered to the owner' }}">{{ $alert->status->label() }}</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('refunds.show', $refund) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="text-center text-body-secondary py-4">
                        <i class="bi bi-arrow-counterclockwise fs-3 d-block mb-2"></i>
                        No refunds recorded yet.
                    </td>
                </tr>
            @endforelse
            </tbody>
            @if ($refunds->isNotEmpty())
                <tfoot class="table-group-divider">
                <tr class="fw-semibold">
                    <td colspan="7">Total on this page</td>
                    <td class="text-end money">{{ \App\Models\Setting::money($refunds->sum('amount')) }}</td>
                    <td colspan="3"></td>
                </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @if ($refunds->hasPages())
        <div class="card-footer">{{ $refunds->links() }}</div>
    @endif
</div>
@endsection
