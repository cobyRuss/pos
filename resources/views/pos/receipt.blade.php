<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $order->order_number }}</title>
    {{-- Intentionally no @vite: the utility stylesheet's preflight would
         reset the fixed millimetre widths this layout depends on. --}}
    <style>
        /*
         * Thermal receipt printers take 72-80mm paper. The width is fixed in
         * millimetres rather than pixels so the browser lays the document out
         * at the real paper size, and the print stylesheet drops all chrome.
         */
        @page { size: 80mm auto; margin: 0; }

        :root { color-scheme: light; }

        body {
            width: 72mm;
            margin: 0 auto;
            padding: 4mm 2mm;
            background: #fff;
            color: #000;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 11px;
            line-height: 1.35;
        }

        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: 700; }
        .muted { color: #444; }
        .big { font-size: 15px; }
        .rule { border-top: 1px dashed #000; margin: 3mm 0; }
        .double-rule { border-top: 3px double #000; margin: 2mm 0; }

        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 0.4mm 0; vertical-align: top; }
        th { text-align: left; font-weight: 700; }
        .num { text-align: right; white-space: nowrap; }

        .qr { display: block; margin: 2mm auto 0; }

        /* Screen preview mimics the paper; print is identical minus the frame. */
        @media screen {
            body { box-shadow: 0 0 0 1px #ddd; margin-top: 8mm; margin-bottom: 8mm; }
        }

        @media print {
            .no-print { display: none !important; }
            body { width: auto; box-shadow: none; margin: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="center">
        <p class="bold big">{{ $business[\App\Models\Setting::BUSINESS_NAME] ?? config('app.name') }}</p>
        @if (! empty($business[\App\Models\Setting::BUSINESS_ADDRESS]))
            <p class="muted">{{ $business[\App\Models\Setting::BUSINESS_ADDRESS] }}</p>
        @endif
        @if (! empty($business[\App\Models\Setting::BUSINESS_PHONE]))
            <p class="muted">{{ $business[\App\Models\Setting::BUSINESS_PHONE] }}</p>
        @endif
    </div>

    <div class="rule"></div>

    <table>
        <tr><th>Order</th><td class="num bold">{{ $order->order_number }}</td></tr>
        <tr><th>Date</th><td class="num">{{ $order->created_at->format('d M Y H:i') }}</td></tr>
        <tr><th>Served by</th><td class="num">{{ $order->user?->name ?? '—' }}</td></tr>
        @if ($order->walkin_customer_name)
            <tr><th>Customer</th><td class="num">{{ $order->walkin_customer_name }}</td></tr>
        @endif
    </table>

    <div class="rule"></div>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th class="num">Qty</th>
                <th class="num">Price</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>
                        {{ $item->product_name }}
                        @if ((float) $item->discount_amount > 0)
                            <br><span class="muted">discount -{{ $currency }}{{ $item->discount_amount }}</span>
                        @endif
                    </td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ $currency }}{{ $item->unit_price }}</td>
                    <td class="num">{{ $currency }}{{ $item->line_total }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="double-rule"></div>

    <table>
        <tr>
            <td colspan="3">Subtotal</td>
            <td class="num">{{ $currency }}{{ $order->subtotal }}</td>
        </tr>
        @if ((float) $order->discount_amount > 0)
            <tr>
                <td colspan="3">Discount ({{ $order->discount_type }})</td>
                <td class="num">-{{ $currency }}{{ $order->discount_amount }}</td>
            </tr>
        @endif
        @if ((float) $order->tax_amount > 0)
            <tr>
                <td colspan="3">Tax {{ rtrim(rtrim(number_format((float) $order->tax_rate, 2, '.', ''), '0'), '.') }}%</td>
                <td class="num">{{ $currency }}{{ $order->tax_amount }}</td>
            </tr>
        @endif
        <tr class="bold big">
            <td colspan="3">TOTAL</td>
            <td class="num">{{ $currency }}{{ $order->total }}</td>
        </tr>
    </table>

    <div class="rule"></div>

    <table>
        <tr>
            <td colspan="3">{{ ucfirst($order->payment_method) }}</td>
            <td class="num">{{ $currency }}{{ $order->amount_paid }}</td>
        </tr>
        @if ((float) $order->change_amount > 0)
            <tr>
                <td colspan="3">Change</td>
                <td class="num">{{ $currency }}{{ $order->change_amount }}</td>
            </tr>
        @endif
        @if ($order->reference_no)
            <tr>
                <td colspan="3">Reference</td>
                <td class="num">{{ $order->reference_no }}</td>
            </tr>
        @endif
    </table>

    @if ($order->is_cancelled)
        <div class="double-rule"></div>
        <p class="center bold">*** CANCELLED ***</p>
        @if ($order->cancel_reason)
            <p class="center muted">{{ $order->cancel_reason }}</p>
        @endif
    @endif

    <div class="rule"></div>

    <p class="center">Items: {{ $order->items->sum('quantity') }}</p>
    @if (! empty($business[\App\Models\Setting::RECEIPT_FOOTER]))
        <p class="center muted">{{ $business[\App\Models\Setting::RECEIPT_FOOTER] }}</p>
    @else
        <p class="center muted">Thank you for your purchase.</p>
    @endif

    <div class="no-print center" style="margin-top: 5mm">
        <button type="button" onclick="window.print()"
                style="border:1px solid #000;border-radius:4px;padding:4px 10px;font:inherit;font-size:11px;cursor:pointer;background:#fff">Print</button>
        <a href="{{ route('orders.show', $order) }}" style="margin-left:8px;font-size:11px">Back to order</a>
    </div>
</body>
</html>
