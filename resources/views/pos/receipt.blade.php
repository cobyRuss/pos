@php
    use App\Models\Setting;

    $receiptWidth = Setting::get('receipt_size', '80mm');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $order->order_number }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">
</head>
<body class="bg-body-secondary receipt-{{ $receiptWidth }}">
<div class="container py-4">
    {{-- Toolbar: hidden when printing --}}
    <div class="no-print d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h5 mb-0">Receipt</h1>
            <small class="text-body-secondary">{{ $order->order_number }}</small>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Print
            </button>
            <a href="{{ route('orders.show', $order) }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to Order
            </a>
            @if (auth()->user()->isStaff())
                <a href="{{ route('pos.index') }}" class="btn btn-success">
                    <i class="bi bi-plus-circle me-1"></i>New Sale
                </a>
            @endif
        </div>
    </div>

    @if ($order->isCancelled())
        <div class="no-print alert alert-danger text-center fw-semibold">
            <i class="bi bi-x-circle me-1"></i>This order was cancelled{{ $order->cancel_reason ? ' - '.$order->cancel_reason : '' }}
        </div>
    @endif

    {{-- Printable receipt --}}
    <div class="print-area d-flex justify-content-center">
        <div class="print-sheet card shadow-sm bg-white" style="width:{{ $receiptWidth }};max-width:100%;">
            <div class="card-body receipt-preview">

                <div class="text-center">
                    <div class="fw-bold">{{ Setting::get('store_name', config('app.name')) }}</div>
                    @if (Setting::get('store_address'))
                        <div>{{ Setting::get('store_address') }}</div>
                    @endif
                    @if (Setting::get('store_phone'))
                        <div>Tel: {{ Setting::get('store_phone') }}</div>
                    @endif
                </div>

                <hr>

                <div class="receipt-line">
                    <span>Order #</span>
                    <span>{{ $order->order_number }}</span>
                </div>
                <div class="receipt-line">
                    <span>Date</span>
                    <span title="{{ $order->created_at->toDayDateTimeString() }}">
                        {{ $order->created_at->format('d M Y') }}
                    </span>
                </div>
                <div class="receipt-line">
                    <span>Time</span>
                    <span>{{ $order->created_at->format('g:i A') }}</span>
                </div>
                <div class="receipt-line">
                    <span>Served by</span>
                    <span>{{ $order->cashier_name }}</span>
                </div>
                <div class="receipt-line">
                    <span>Payment</span>
                    <span>{{ $order->payment_method->label() }}</span>
                </div>

                <hr>

                <table class="w-100 border-0">
                    <tbody>
                    @foreach ($order->items as $item)
                        <tr class="align-top">
                            <td colspan="2">
                                <span class="receipt-item-name">{{ $item->product_name }}</span>
                                <div class="text-body-secondary">
                                    {{ $item->quantity }} &times; {{ Setting::money($item->unit_price) }}
                                    @if ($item->refunded_quantity > 0)
                                        <span class="text-danger">({{ $item->refunded_quantity }} refunded)</span>
                                    @endif
                                </div>
                            </td>
                            <td class="text-end align-middle" style="white-space:nowrap;">{{ Setting::money($item->line_total) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>

                <hr>

                <div class="receipt-line">
                    <span>Subtotal</span>
                    <span>{{ Setting::money($order->subtotal) }}</span>
                </div>

                @if ($order->discount_amount > 0)
                    <div class="receipt-line">
                        <span>
                            Discount
                            <span class="text-body-secondary">
                                ({{ $order->discount_type->value === 'percentage'
                                    ? rtrim(rtrim(number_format((float) $order->discount_value, 2, '.', ''), '0'), '.').'%'
                                    : Setting::money($order->discount_value) }})
                            </span>
                        </span>
                        <span>- {{ Setting::money($order->discount_amount) }}</span>
                    </div>
                @endif

                @if ((float) $order->tax_amount > 0)
                    <div class="receipt-line">
                        <span>Tax ({{ rtrim(rtrim(number_format((float) $order->tax_rate, 2, '.', ''), '0'), '.') }}%)</span>
                        <span>{{ Setting::money($order->tax_amount) }}</span>
                    </div>
                @endif

                <div class="receipt-line fw-bold fs-6 mt-1">
                    <span>TOTAL</span>
                    <span>{{ Setting::money($order->total) }}</span>
                </div>

                <div class="receipt-line">
                    <span>Paid</span>
                    <span>{{ Setting::money($order->paid_amount) }}</span>
                </div>

                @if ((float) $order->change_amount > 0)
                    <div class="receipt-line">
                        <span>Change</span>
                        <span>{{ Setting::money($order->change_amount) }}</span>
                    </div>
                @endif

                @if ((float) $order->refunded_amount > 0)
                    <div class="receipt-line text-danger">
                        <span>Refunded</span>
                        <span>- {{ Setting::money($order->refunded_amount) }}</span>
                    </div>
                    <div class="receipt-line fw-bold">
                        <span>NET</span>
                        <span>{{ Setting::money($order->net_total) }}</span>
                    </div>
                @endif

                @if ($order->customer_note)
                    <hr>
                    <div><span class="text-body-secondary">Note:</span> {{ $order->customer_note }}</div>
                @endif

                @if ($order->isCancelled())
                    <hr>
                    <div class="text-center fw-bold text-danger">CANCELLED</div>
                    @if ($order->cancelled_at)
                        <div class="text-center text-body-secondary">
                            {{ $order->cancelled_at->format('d M Y') }} at {{ $order->cancelled_at->format('g:i A') }}
                        </div>
                    @endif
                @endif

                <hr>

                <div class="text-center">
                    <div>{{ $order->items->sum('quantity') }} item(s) &middot; {{ $order->items->count() }} line(s)</div>
                    @if (Setting::get('receipt_footer'))
                        <div class="mt-2">{{ Setting::get('receipt_footer') }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
