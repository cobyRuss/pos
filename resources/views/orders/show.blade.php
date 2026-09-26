@extends('layouts.app')

@section('title', $order->order_number)

@section('content')
    <x-page-header :title="$order->order_number">
        <a href="{{ route('orders.index') }}" class="text-sm text-slate-500 hover:text-slate-800">&larr; Back to orders</a>
    </x-page-header>

    @if ($order->is_cancelled)
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <p class="font-semibold">This order was cancelled{{ $order->cancelled_at ? ' on '.$order->cancelled_at->format('d M Y H:i') : '' }}.</p>
            @if ($order->cancelledBy)
                <p class="mt-0.5">By {{ $order->cancelledBy->name }}.</p>
            @endif
            @if ($order->cancel_reason)
                <p class="mt-0.5">Reason: {{ $order->cancel_reason }}</p>
            @endif
            <p class="mt-0.5">The stock for every line has been returned to the shelf.</p>
        </div>
    @elseif ($order->fully_refunded)
        <div class="mb-4 rounded-lg border border-slate-300 bg-slate-100 px-4 py-3 text-sm text-slate-700">
            <p class="font-semibold">This order has been fully refunded.</p>
            <p class="mt-0.5">The full {{ $order->total }} was returned to the customer.</p>
        </div>
    @elseif ($order->partially_refunded)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-semibold">Part of this order has been refunded.</p>
            <p class="mt-0.5">
                {{ $order->refunded_total }} refunded, leaving {{ number_format((float) $order->net_total, 2) }} of the
                {{ $order->total }} sale.
                <a href="{{ route('admin.refunds.index') }}" class="font-medium underline">View refunds</a>
            </p>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Item</th>
                        <th class="px-4 py-3 text-right">Unit price</th>
                        <th class="px-4 py-3 text-right">Qty</th>
                        <th class="px-4 py-3 text-right">Discount</th>
                        <th class="px-4 py-3 text-right">Line total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($order->items as $item)
                        <tr>
                            <td class="px-4 py-3">
                                @if ($item->product && $item->product->is_active)
                                    <a href="{{ route('products.show', $item->product) }}" class="font-medium text-slate-800 hover:underline">{{ $item->product_name }}</a>
                                @else
                                    <span class="font-medium text-slate-800">{{ $item->product_name }}</span>
                                    <span class="block text-xs text-slate-400">{{ $item->sku }} &middot; no longer sold</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right text-slate-600">{{ $item->unit_price }}</td>
                            <td class="px-4 py-3 text-right text-slate-600">{{ $item->quantity }}</td>
                            <td class="px-4 py-3 text-right text-slate-600">
                                {{ (float) $item->discount_amount > 0 ? '-'.number_format((float) $item->discount_amount, 2) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900">{{ $item->line_total }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="border-t border-slate-200 px-4 py-3">
                <dl class="ml-auto max-w-xs space-y-1 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Subtotal</dt>
                        <dd class="font-medium">{{ $order->subtotal }}</dd>
                    </div>
                    @if ((float) $order->discount_amount > 0)
                        <div class="flex justify-between text-emerald-700">
                            <dt>Discount ({{ $order->discount_type }} {{ (float) $order->discount_value > 0 ? $order->discount_value : '' }})</dt>
                            <dd class="font-medium">-{{ $order->discount_amount }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Tax ({{ rtrim(rtrim(number_format((float) $order->tax_rate, 2, '.', ''), '0'), '.') }}%)</dt>
                        <dd class="font-medium">{{ $order->tax_amount }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-slate-200 pt-2 text-base">
                        <dt class="font-semibold text-slate-900">Total</dt>
                        <dd class="font-bold text-slate-900">{{ $order->total }}</dd>
                    </div>
                    @if ((float) $order->refunded_total > 0)
                        <div class="flex justify-between text-rose-700">
                            <dt>Refunded</dt>
                            <dd class="font-medium">-{{ $order->refunded_total }}</dd>
                        </div>
                        <div class="flex justify-between border-t border-slate-200 pt-2 text-base">
                            <dt class="font-semibold text-slate-900">Net kept</dt>
                            <dd class="font-bold text-slate-900">{{ number_format((float) $order->net_total, 2) }}</dd>
                        </div>
                    @endif
                </dl>
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-2 text-sm font-semibold text-slate-900">Payment</h3>
                <dl class="space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Method</dt><dd class="font-medium">{{ ucfirst($order->payment_method) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Tendered</dt><dd class="font-medium">{{ $order->amount_paid }}</dd></div>
                    @if ((float) $order->change_amount > 0)
                        <div class="flex justify-between"><dt class="text-slate-500">Change</dt><dd class="font-medium">{{ $order->change_amount }}</dd></div>
                    @endif
                    @if ($order->reference_no)
                        <div class="flex justify-between"><dt class="text-slate-500">Reference</dt><dd class="font-medium">{{ $order->reference_no }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-slate-500">Cashier</dt><dd class="font-medium">{{ $order->user?->name ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Placed</dt><dd class="font-medium">{{ $order->created_at->format('d M Y H:i') }}</dd></div>
                </dl>

                <a href="{{ route('pos.receipt', $order) }}"
                   class="mt-3 block rounded-lg border border-slate-300 px-3 py-2 text-center text-sm font-medium text-slate-700 hover:bg-slate-50">
                    View / print receipt
                </a>
            </div>

            @if ($order->notes)
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 class="mb-1 text-sm font-semibold text-slate-900">Notes</h3>
                    <p class="text-sm text-slate-600">{{ $order->notes }}</p>
                </div>
            @endif

            @can('refunds.process')
                @if (! $order->is_cancelled)
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h3 class="text-sm font-semibold text-slate-900">Return items</h3>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Refund what the customer hands back. Restockable items return to sellable
                            stock; damaged items are recorded as a write-off and are not resold.
                        </p>

                        @php($returnable = $order->items->filter(fn ($item) => $item->returnable_quantity > 0))

                        @if ($returnable->isEmpty())
                            <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-500">
                                Everything on this order has already been returned.
                            </p>
                        @else
                            <form method="POST" action="{{ route('admin.refunds.store', $order) }}" class="mt-3 space-y-2">
                                @csrf
                                @foreach ($returnable as $item)
                                    <div class="rounded-lg border border-slate-200 p-2">
                                        <div class="flex items-center justify-between gap-2">
                                            <p class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800" title="{{ $item->product_name }}">
                                                {{ $item->product_name }}
                                            </p>
                                            <span class="shrink-0 text-xs text-slate-500">
                                                {{ $item->returnable_quantity }} of {{ $item->quantity }} returnable
                                            </span>
                                        </div>
                                        <div class="mt-1 flex gap-1">
                                            <input type="hidden" name="items[{{ $loop->index }}][order_item_id]" value="{{ $item->id }}">
                                            <input type="number" name="items[{{ $loop->index }}][quantity]" value="0" min="0"
                                                   max="{{ $item->returnable_quantity }}" placeholder="0"
                                                   class="w-20 rounded-lg border-slate-300 text-xs shadow-sm">
                                            <select name="items[{{ $loop->index }}][condition]"
                                                    class="w-32 rounded-lg border-slate-300 text-xs shadow-sm">
                                                <option value="restockable">Restockable</option>
                                                <option value="damaged">Damaged</option>
                                            </select>
                                        </div>
                                    </div>
                                @endforeach

                                <select name="method" required class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
                                    @foreach (\App\Models\Refund::methods() as $method)
                                        <option value="{{ $method }}">{{ ucfirst($method) }}</option>
                                    @endforeach
                                </select>
                                <input type="text" name="reference_no" value="{{ old('reference_no') }}"
                                       placeholder="Reference no. (digital payouts)"
                                       class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
                                <input type="text" name="reason" value="{{ old('reason') }}" placeholder="Reason (optional)"
                                       class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
                                <button type="submit" class="w-full rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
                                    Process return
                                </button>
                            </form>

                            <form method="POST" action="{{ route('admin.refunds.all', $order) }}" class="mt-2"
                                  onsubmit="return confirm('Refund every remaining item on {{ $order->order_number }}?');">
                                @csrf
                                <input type="hidden" name="method" value="{{ $order->payment_method }}">
                                <input type="text" name="reason" placeholder="Reason for the full return (optional)"
                                       class="mb-2 w-full rounded-lg border-slate-300 text-sm shadow-sm">
                                <button type="submit" class="w-full rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                                    Refund the whole order
                                </button>
                            </form>
                        @endif
                    </div>
                @endif
            @endcan

            @if ($canCancel && ! $order->is_cancelled)
                <div class="rounded-xl border border-rose-200 bg-white p-4 shadow-sm">
                    <h3 class="mb-2 text-sm font-semibold text-rose-800">Cancel this order</h3>
                    <p class="mb-2 text-xs text-slate-500">
                        Cancelling marks the order void and returns every line to stock. It cannot be undone.
                    </p>
                    <form method="POST" action="{{ route('orders.cancel', $order) }}"
                          onsubmit="return confirm('Cancel {{ $order->order_number }} and restore its stock?');">
                        @csrf
                        <input type="text" name="cancel_reason" value="{{ old('cancel_reason') }}" required
                               placeholder="Reason (required)"
                               class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-400 focus:ring-rose-400">
                        @error('cancel_reason')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                        <button type="submit" class="mt-2 w-full rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500">
                            Cancel order &amp; restore stock
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </div>
@endsection
