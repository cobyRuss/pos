@extends('layouts.app')

@section('title', 'Order '.$order->order_number)
@section('page-title', 'Order '.$order->order_number)
@section('page-subtitle', 'Placed '.$order->created_at->format('d/m/Y \a\t H:i'))

@section('content')
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="{{ route('orders.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Orders
    </a>
    <a href="{{ route('orders.receipt', $order) }}" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-receipt me-1"></i>View / Print Receipt
    </a>

    {{-- Refunds are a cashier action: the owner is never on the till, so this
         has to be reachable by staff. Cancellations stay admin-only. --}}
    @if (! $order->isCancelled() && $order->refundableItems->isNotEmpty())
        <a href="{{ route('refunds.create', $order) }}" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Refund / Return
        </a>
    @endif

    @if (auth()->user()->isAdmin())
        @if (! $order->isCancelled() && (float) $order->refunded_amount === 0.0)
            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#cancelModal">
                <i class="bi bi-x-circle me-1"></i>Cancel Order
            </button>
        @endif
    @endif
</div>

@if ($order->isCancelled())
    <div class="alert alert-danger">
        <strong>This order was cancelled</strong> on {{ $order->cancelled_at?->format('d/m/Y H:i') }}.
        @if ($order->cancel_reason)
            <br>Reason: {{ $order->cancel_reason }}
        @endif
        <br><small>All stock from this order has been returned to inventory.</small>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Items</div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                    <tr>
                        <th>Product</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Line Total</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($order->items as $item)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $item->product_name }}</div>
                                <div class="text-body-secondary small">
                                    @if ($item->product && ! $item->product->is_active)
                                        <span class="badge text-bg-secondary ms-1">product inactive</span>
                                    @endif
                                </div>
                            </td>
                            <td class="text-center">
                                {{ $item->quantity }}
                                @if ($item->refunded_quantity > 0)
                                    <div><span class="badge text-bg-danger">{{ $item->refunded_quantity }} refunded</span></div>
                                @endif
                            </td>
                            <td class="text-end money">{{ \App\Models\Setting::money($item->unit_price) }}</td>
                            <td class="text-end money fw-semibold">{{ \App\Models\Setting::money($item->line_total) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-body-tertiary">
                <dl class="row mb-0 small ms-auto" style="max-width:320px;">
                    <dt class="col-7 text-body-secondary fw-normal">Subtotal</dt>
                    <dd class="col-5 text-end money">{{ \App\Models\Setting::money($order->subtotal) }}</dd>

                    @if ((float) $order->discount_amount > 0)
                        <dt class="col-7 text-body-secondary fw-normal">
                            Discount ({{ $order->discount_type->value === 'percentage'
                                ? rtrim(rtrim(number_format((float) $order->discount_value, 2, '.', ''), '0'), '.').'%'
                                : \App\Models\Setting::money($order->discount_value) }})
                        </dt>
                        <dd class="col-5 text-end money text-success">- {{ \App\Models\Setting::money($order->discount_amount) }}</dd>
                    @endif

                    @if ((float) $order->tax_amount > 0)
                        <dt class="col-7 text-body-secondary fw-normal">
                            Tax ({{ rtrim(rtrim(number_format((float) $order->tax_rate, 2, '.', ''), '0'), '.') }}%)
                        </dt>
                        <dd class="col-5 text-end money">{{ \App\Models\Setting::money($order->tax_amount) }}</dd>
                    @endif

                    <dt class="col-7 fw-bold">Total</dt>
                    <dd class="col-5 text-end money fw-bold">{{ \App\Models\Setting::money($order->total) }}</dd>

                    @if ((float) $order->refunded_amount > 0)
                        <dt class="col-7 text-danger fw-normal">Refunded</dt>
                        <dd class="col-5 text-end money text-danger">- {{ \App\Models\Setting::money($order->refunded_amount) }}</dd>
                        <dt class="col-7 fw-bold">Net</dt>
                        <dd class="col-5 text-end money fw-bold">{{ \App\Models\Setting::money($order->net_total) }}</dd>
                    @endif

                    <dt class="col-7 text-body-secondary fw-normal">Paid</dt>
                    <dd class="col-5 text-end money">{{ \App\Models\Setting::money($order->paid_amount) }}</dd>

                    @if ((float) $order->change_amount > 0)
                        <dt class="col-7 text-body-secondary fw-normal">Change</dt>
                        <dd class="col-5 text-end money">{{ \App\Models\Setting::money($order->change_amount) }}</dd>
                    @endif
                </dl>
            </div>
        </div>

        @if ($order->refunds->isNotEmpty())
            <div class="card mt-3">
                <div class="card-header">Refunds &amp; Returns</div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                        <tr>
                            <th>Refund #</th>
                            <th>Date</th>
                            <th>Reason</th>
                            <th>Method</th>
                            <th>Processed by</th>
                            <th class="text-end">Amount</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($order->refunds as $refund)
                            <tr>
                                <td class="fw-semibold">{{ $refund->refund_number }}</td>
                                <td class="text-nowrap">{{ $refund->refunded_at->format('d/m/Y H:i') }}</td>
                                <td>{{ $refund->reason_code?->label() ?? $refund->reason }}</td>
                                <td>{{ $refund->method->label() }}</td>
                                <td>{{ $refund->processed_by }}</td>
                                <td class="text-end money text-danger">- {{ \App\Models\Setting::money($refund->amount) }}</td>
                                <td class="text-end">
                                    @if (auth()->user()->isAdmin())
                                        <a href="{{ route('refunds.show', $refund) }}" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Order Details</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-body-secondary fw-normal">Order #</dt>
                    <dd class="col-7 fw-semibold">{{ $order->order_number }}</dd>

                    <dt class="col-5 text-body-secondary fw-normal">Status</dt>
                    <dd class="col-7">
                        <span class="badge {{ $order->status->badgeClass() }}">{{ $order->status->label() }}</span>
                    </dd>

                    <dt class="col-5 text-body-secondary fw-normal">Cashier</dt>
                    <dd class="col-7">{{ $order->cashier_name }}</dd>

                    <dt class="col-5 text-body-secondary fw-normal">Payment</dt>
                    <dd class="col-7">{{ $order->payment_method->label() }}</dd>

                    <dt class="col-5 text-body-secondary fw-normal">Total units</dt>
                    <dd class="col-7">{{ $order->items->sum('quantity') }}</dd>

                    <dt class="col-5 text-body-secondary fw-normal">Created</dt>
                    <dd class="col-7">{{ $order->created_at->format('d/m/Y H:i') }}</dd>
                </dl>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">Note</div>
            <div class="card-body">
                <form method="POST" action="{{ route('orders.update', $order) }}">
                    @csrf
                    @method('PUT')
                    <div class="mb-2">
                        <label for="customer_note" class="form-label small fw-semibold">Order Note</label>
                        <input type="text" class="form-control form-control-sm @error('customer_note') is-invalid @enderror"
                               id="customer_note" name="customer_note" maxlength="255"
                               value="{{ old('customer_note', $order->customer_note) }}"
                               placeholder="e.g. take-away, special request">
                        @error('customer_note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Save Note</button>
                </form>
                <div class="form-text mt-2">
                    Sales are walk-in. Notes are optional and used for order context only.
                </div>
            </div>
        </div>
    </div>
</div>

@if (auth()->user()->isAdmin() && ! $order->isCancelled() && (float) $order->refunded_amount === 0.0)
    <div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" action="{{ route('orders.cancel', $order) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="cancelModalLabel">Cancel order {{ $order->order_number }}?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>
                        Cancelling will return
                        <strong>{{ $order->items->sum('quantity') }} unit(s)</strong> to inventory
                        and exclude this order from revenue reporting.
                    </p>
                    <div class="mb-3">
                        <label for="cancel_reason" class="form-label">Reason <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('reason') is-invalid @enderror"
                               id="cancel_reason" name="reason" required maxlength="255"
                               placeholder="e.g. customer changed mind">
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep Order</button>
                    <button type="submit" class="btn btn-danger">Cancel Order &amp; Restock</button>
                </div>
            </form>
        </div>
    </div>
@endif
@endsection
