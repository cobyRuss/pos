@extends('layouts.app')

@section('title', 'Orders')

@section('content')
    <x-page-header title="{{ $canSeeAll ? 'All Orders' : 'My Sales' }}">
        <span class="text-sm text-slate-500">{{ $orders->total() }} {{ Str::plural('order', $orders->total()) }}</span>
    </x-page-header>

    <form method="GET" action="{{ route('orders.index') }}" class="mb-4 grid gap-2 rounded-xl border border-slate-200 bg-white p-3 sm:grid-cols-2 lg:grid-cols-6">
        <div class="lg:col-span-2">
            <x-text-input name="q" type="search" :value="request('q')" placeholder="Order no, customer, reference…" />
        </div>
        <div>
            <select name="status" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">Any status</option>
                @foreach ([\App\Models\Order::STATUS_COMPLETED => 'Completed', \App\Models\Order::STATUS_CANCELLED => 'Cancelled'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <select name="payment_method" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">Any payment</option>
                @foreach (['cash' => 'Cash', 'card' => 'Card', 'digital' => 'Digital'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('payment_method') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <input type="date" name="from" value="{{ request('from') }}"
                   class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <div>
            <input type="date" name="to" value="{{ request('to') }}"
                   class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <div class="flex gap-2 sm:col-span-2 lg:col-span-6">
            <button type="submit" class="rounded-lg bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">Filter</button>
            <a href="{{ route('orders.index') }}" class="py-2 text-sm text-slate-500 hover:text-slate-800">Reset</a>
        </div>
    </form>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Order</th>
                    <th class="px-4 py-3">When</th>
                    @if ($canSeeAll)
                        <th class="px-4 py-3">Cashier</th>
                    @endif
                    <th class="px-4 py-3">Customer</th>
                    <th class="px-4 py-3">Payment</th>
                    <th class="px-4 py-3 text-right">Items</th>
                    <th class="px-4 py-3 text-right">Total</th>
                    <th class="px-4 py-3 text-right">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($orders as $order)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('orders.show', $order) }}" class="font-medium text-emerald-700 hover:underline">
                                {{ $order->order_number }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $order->created_at->format('d M Y H:i') }}</td>
                        @if ($canSeeAll)
                            <td class="px-4 py-3 text-slate-600">{{ $order->user?->name ?? '—' }}</td>
                        @endif
                        <td class="px-4 py-3 text-slate-600">{{ $order->walkin_customer_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ ucfirst($order->payment_method) }}</td>
                        <td class="px-4 py-3 text-right text-slate-600">{{ $order->items_sum_quantity ?? 0 }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-slate-900">{{ $order->total }}</td>
                        <td class="px-4 py-3 text-right">
                            @if ($order->is_cancelled)
                                <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">Cancelled</span>
                            @elseif ($order->partially_refunded)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">Refunded</span>
                            @else
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Completed</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $canSeeAll ? 8 : 7 }}" class="px-4 py-10 text-center text-slate-400">
                            No orders match these filters yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $orders->links() }}</div>
@endsection
