@extends('layouts.app')

@section('title', 'Refund '.$refund->refund_number)
@section('page-title', 'Refund '.$refund->refund_number)
@section('page-subtitle', 'Against order '.$refund->order->order_number)

@section('content')
<div class="d-flex flex-wrap gap-2 mb-3">
    @if (auth()->user()->isAdmin())
        <a href="{{ route('admin.refunds.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Refunds
        </a>
    @endif
    <a href="{{ route('orders.show', $refund->order) }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-receipt me-1"></i>View Order
    </a>
    @if ($refund->order->refundableItems->isNotEmpty())
        <a href="{{ route('refunds.create', $refund->order) }}" class="btn btn-warning btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Refund More
        </a>
    @endif
    <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>Print
    </button>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">Refunded items</div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Unit price</th>
                        <th class="text-end">Amount</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($refund->items as $item)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $item->product_name }}</div>
                            </td>
                            <td class="text-center">{{ $item->quantity }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($item->unit_price) }}</td>
                            <td class="text-end money fw-semibold">{{ \App\Models\Setting::money($item->amount) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot class="table-group-divider">
                    <tr class="fw-semibold">
                        <td colspan="3" class="text-end">Refund total</td>
                        <td class="text-end money">{{ \App\Models\Setting::money($refund->amount) }}</td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Original order lines</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th class="text-center">Qty</th>
                        <th class="text-center">Refunded</th>
                        <th class="text-center">Still held</th>
                        <th class="text-end">Line total</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($refund->order->items as $item)
                        <tr>
                            <td>{{ $item->product_name }}</td>
                            <td class="text-center">{{ $item->quantity }}</td>
                            <td class="text-center">
                                <span class="badge text-bg-warning">{{ $item->refunded_quantity }}</span>
                            </td>
                            <td class="text-center">{{ $item->refundable_quantity }}</td>
                            <td class="text-end money">{{ \App\Models\Setting::money($item->line_total) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">Refund details</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-body-secondary fw-normal">Status</dt>
                    <dd class="col-7">
                        <span class="badge {{ $refund->status->badgeClass() }}">{{ $refund->status->label() }}</span>
                    </dd>

                    <dt class="col-5 text-body-secondary fw-normal">Amount</dt>
                    <dd class="col-7 money fw-semibold">{{ \App\Models\Setting::money($refund->amount) }}</dd>

                    <dt class="col-5 text-body-secondary fw-normal">of which tax</dt>
                    <dd class="col-7 money">{{ \App\Models\Setting::money($refund->tax_amount) }}</dd>

                    <dt class="col-5 text-body-secondary fw-normal">Method</dt>
                    <dd class="col-7">{{ $refund->method->label() }}</dd>

                    <dt class="col-5 text-body-secondary fw-normal">Reason</dt>
                    <dd class="col-7">
                        @if ($refund->reason_code)
                            <span class="badge {{ $refund->reason_code->badgeClass() }}">{{ $refund->reason_code->label() }}</span>
                        @endif
                        @if ($refund->reason)
                            <div class="mt-1">{{ $refund->reason }}</div>
                        @elseif (! $refund->reason_code)
                            <span class="text-body-secondary">Not recorded</span>
                        @endif
                    </dd>

                    <dt class="col-5 text-body-secondary fw-normal">Processed by</dt>
                    <dd class="col-7">
                        {{ $refund->processed_by }}
                        <div class="text-body-secondary">{{ \App\Support\DateFormat::dateTime($refund->refunded_at) }}</div>
                    </dd>

                    <dt class="col-5 text-body-secondary fw-normal">Review</dt>
                    <dd class="col-7">
                        @if (! $refund->review_required)
                            <span class="text-body-secondary">Under the review threshold</span>
                        @elseif ($refund->isReviewed())
                            <span class="badge text-bg-success">
                                Reviewed by {{ $refund->reviewer?->name ?? 'admin' }}
                            </span>
                            <div class="text-body-secondary small">{{ \App\Support\DateFormat::dateTime($refund->reviewed_at) }}</div>
                        @else
                            <span class="badge text-bg-danger">Awaiting review</span>
                            @if (auth()->user()->isAdmin())
                                <form method="POST" action="{{ route('admin.refunds.review', $refund) }}" class="mt-2">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-check2 me-1"></i>Mark as reviewed
                                    </button>
                                </form>
                            @endif
                        @endif
                    </dd>

                    @php $alert = $refund->notifications->firstWhere('channel', \App\Services\RefundService::ALERT_CHANNEL); @endphp
                    <dt class="col-5 text-body-secondary fw-normal">Owner alert</dt>
                    <dd class="col-7">
                        @if (! $alert)
                            <span class="text-body-secondary">Not tracked</span>
                        @else
                            <span class="badge {{ $alert->status->badgeClass() }}">{{ $alert->status->label() }}</span>
                            <div class="text-body-secondary small">
                                {{ $alert->attempts }} attempt(s)
                                @if ($alert->sent_at) · {{ \App\Support\DateFormat::dateTime($alert->sent_at) }} @endif
                            </div>
                            @if ($alert->error)
                                <div class="text-danger small">{{ $alert->error }}</div>
                            @endif
                        @endif
                    </dd>

                    @if ($refund->note)
                        <dt class="col-5 text-body-secondary fw-normal">Note</dt>
                        <dd class="col-7">{{ $refund->note }}</dd>
                    @endif
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Order summary</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-6 text-body-secondary fw-normal">Order number</dt>
                    <dd class="col-6">{{ $refund->order->order_number }}</dd>

                    <dt class="col-6 text-body-secondary fw-normal">Order total</dt>
                    <dd class="col-6 money">{{ \App\Models\Setting::money($refund->order->total) }}</dd>

                    <dt class="col-6 text-body-secondary fw-normal">Total refunded</dt>
                    <dd class="col-6 money">{{ \App\Models\Setting::money($refund->order->refunded_amount) }}</dd>

                    <dt class="col-6 text-body-secondary fw-normal">Net revenue</dt>
                    <dd class="col-6 money fw-semibold">{{ \App\Models\Setting::money($refund->order->net_total) }}</dd>

                    <dt class="col-6 text-body-secondary fw-normal">Cashier</dt>
                    <dd class="col-6">{{ $refund->order->cashier_name }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection
