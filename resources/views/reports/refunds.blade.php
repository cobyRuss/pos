@extends('layouts.app')

@section('title', 'Cash Reconciliation')
@section('page-title', 'Cash Reconciliation')
@section('page-subtitle', $range.' — refunds and expected drawer, per cashier')

@section('content')
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.reports.refunds') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="from" class="form-label small fw-semibold">From</label>
                <input type="date" class="form-control" id="from" name="from" value="{{ $filters['from'] ?? '' }}">
            </div>
            <div class="col-md-3">
                <label for="to" class="form-label small fw-semibold">To</label>
                <input type="date" class="form-control" id="to" name="to" value="{{ $filters['to'] ?? '' }}">
            </div>
            <div class="col-md-3">
                <label for="user_id" class="form-label small fw-semibold">Cashier</label>
                <select class="form-select" id="user_id" name="user_id">
                    <option value="">All cashiers</option>
                    @foreach ($cashiers as $cashier)
                        <option value="{{ $cashier->id }}" @selected(($filters['user_id'] ?? null) == $cashier->id)>
                            {{ $cashier->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                    <a href="{{ route('admin.reports.refunds', array_merge(request()->query(), ['export' => 1])) }}"
                       class="btn btn-outline-secondary" title="Download CSV">
                        <i class="bi bi-download"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

@if ($totals['refunded'] > 0)
    <div class="alert {{ $totals['refund_rate'] > 15 ? 'alert-warning' : 'alert-light' }} border d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-calculator"></i>
        <span>
            Across {{ $totals['cashiers'] }} cashier(s): {{ $totals['orders'] }} orders took
            <strong>{{ \App\Models\Setting::money($totals['gross']) }}</strong> in cash and GCash.
            <strong>{{ \App\Models\Setting::money($totals['refunded']) }}</strong>
            ({{ number_format($totals['refund_rate'], 1) }}%) went back out, of which
            <strong>{{ \App\Models\Setting::money($totals['cash_out']) }}</strong> left the cash drawer.
        </span>
    </div>
@else
    <div class="alert alert-success">
        <i class="bi bi-check2-circle me-1"></i>
        No refunds in this period. Every peso taken is still in the drawer.
    </div>
@endif

<div class="card mb-3">
    <div class="card-header">
        <i class="bi bi-person-badge me-1"></i>By cashier
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Cashier</th>
                <th class="text-center">Orders</th>
                <th class="text-end">Gross sales</th>
                <th class="text-end">Cash taken</th>
                <th class="text-end">Cash paid out</th>
                <th class="text-end">Expected in drawer</th>
                <th class="text-center">Refunds</th>
                <th class="text-end">Refunded</th>
                <th class="text-end">Refund rate</th>
                <th class="text-center">Flagged</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($byCashier as $row)
                <tr>
                    <td class="fw-semibold">{{ $row->name }}</td>
                    <td class="text-center">{{ number_format($row->orders) }}</td>
                    <td class="text-end money">{{ \App\Models\Setting::money($row->gross) }}</td>
                    <td class="text-end money">{{ \App\Models\Setting::money($row->cash_sales) }}</td>
                    <td class="text-end money {{ $row->cash_out > 0 ? 'text-danger fw-semibold' : 'text-body-secondary' }}">
                        {{ \App\Models\Setting::money($row->cash_out) }}
                    </td>
                    <td class="text-end money fw-semibold">{{ \App\Models\Setting::money($row->expected_cash) }}</td>
                    <td class="text-center">{{ $row->refunds }}</td>
                    <td class="text-end money">{{ \App\Models\Setting::money($row->refunded) }}</td>
                    <td class="text-end">
                        @if ($row->refund_rate >= 15)
                            <span class="badge text-bg-warning">{{ number_format($row->refund_rate, 1) }}%</span>
                        @else
                            <span class="text-body-secondary">{{ number_format($row->refund_rate, 1) }}%</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if ($row->flagged_count > 0)
                            <a href="{{ route('admin.refunds.index', ['status' => 'unreviewed']) }}" class="badge text-bg-danger">
                                {{ $row->flagged_count }}
                            </a>
                        @else
                            <span class="text-body-secondary">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="text-center text-body-secondary py-4">
                        No sales in this period, so there is nothing to reconcile.
                    </td>
                </tr>
            @endforelse
            </tbody>
            @if ($byCashier->isNotEmpty())
                <tfoot class="table-group-divider">
                <tr class="fw-semibold">
                    <td>Total</td>
                    <td class="text-center">{{ number_format($totals['orders']) }}</td>
                    <td class="text-end money">{{ \App\Models\Setting::money($totals['gross']) }}</td>
                    <td class="text-end money">{{ \App\Models\Setting::money($totals['cash_sales']) }}</td>
                    <td class="text-end money">{{ \App\Models\Setting::money($totals['cash_out']) }}</td>
                    <td class="text-end money">{{ \App\Models\Setting::money($totals['expected_cash']) }}</td>
                    <td class="text-center">{{ $totals['refunds'] }}</td>
                    <td class="text-end money">{{ \App\Models\Setting::money($totals['refunded']) }}</td>
                    <td></td>
                    <td class="text-center">{{ $totals['flagged_count'] }}</td>
                </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <div class="card-footer small text-body-secondary">
        <i class="bi bi-info-circle me-1"></i>
        This store runs without fixed shifts, so there is no counted-drawer figure to compare against.
        “Expected in drawer” is what each cashier's cash takings <em>should</em> have left after
        cash refunds. Count the drawer and any difference is the number to investigate — then match
        the timestamp against the CCTV.
        @if ($dailyLimit > 0)
            <br>Cashier daily refund limit is {{ \App\Models\Setting::money($dailyLimit) }}; the owner is exempt.
        @endif
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-calendar3 me-1"></i>Refunds by day</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                    <tr><th>Day</th><th class="text-center">Refunds</th><th class="text-end">Amount</th><th class="text-center">Flagged</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($byDay as $day)
                        <tr>
                            <td>{{ \Illuminate\Support\Carbon::parse($day->day)->format('D d M Y') }}</td>
                            <td class="text-center">{{ $day->refunds }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($day->refunded) }}</td>
                            <td class="text-center">{{ $day->flagged_count ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-body-secondary py-3">No refunds in this period.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-tags me-1"></i>Refunds by reason</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                    <tr><th>Reason</th><th class="text-center">Count</th><th class="text-end">Amount</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($byReason as $row)
                        <tr>
                            <td>
                                @php $reason = \App\Enums\RefundReason::tryFrom($row->code ?? ''); @endphp
                                @if ($reason)
                                    <span class="badge {{ $reason->badgeClass() }}">{{ $reason->label() }}</span>
                                @else
                                    <span class="text-body-secondary">Not recorded</span>
                                @endif
                            </td>
                            <td class="text-center">{{ $row->refunds }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($row->refunded) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-body-secondary py-3">No refunds in this period.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@if ($flagged->isNotEmpty())
    <div class="card mt-3 border-danger">
        <div class="card-header bg-danger-subtle text-danger-emphasis">
            <i class="bi bi-exclamation-octagon me-1"></i>
            Above {{ \App\Models\Setting::money($threshold) }} — waiting for review
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                <tr><th>Refund #</th><th>When</th><th>Cashier</th><th>Order</th><th>Reason</th><th class="text-end">Amount</th><th>Reviewed</th></tr>
                </thead>
                <tbody>
                @foreach ($flagged as $row)
                    <tr>
                        <td class="fw-semibold"><a href="{{ route('refunds.show', $row) }}" class="text-decoration-none">{{ $row->refund_number }}</a></td>
                        <td class="text-nowrap">{{ $row->refunded_at->format('d/m H:i') }}</td>
                        <td>{{ $row->processed_by }}</td>
                        <td><a href="{{ route('orders.show', $row->order) }}" class="text-decoration-none">{{ $row->order?->order_number }}</a></td>
                        <td class="small">{{ $row->reason_code?->label() ?? '—' }}</td>
                        <td class="text-end money fw-semibold">{{ \App\Models\Setting::money($row->amount) }}</td>
                        <td>{{ $row->isReviewed() ? 'Yes' : 'No' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
