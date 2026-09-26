@extends('layouts.app')

@section('title', 'Sales report')

@section('content')
    @php
        use App\Support\Money;

        $money = fn (int $cents) => $currency.Money::fromCents($cents);
    @endphp

    <x-page-header title="Sales report">
        <a href="{{ route('admin.reports.index') }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Rolling report
        </a>
        <a href="{{ route('admin.reports.print', ['report' => 'sales'] + $filters) }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Print view
        </a>
        <a href="{{ route('admin.reports.sales', array_merge($filters, ['format' => 'csv'])) }}"
           class="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
            Export CSV
        </a>
    </x-page-header>

    <form method="GET" action="{{ route('admin.reports.sales') }}" class="mb-4 grid gap-2 rounded-xl border border-slate-200 bg-white p-3 sm:grid-cols-6">
        <div>
            <label for="from" class="block text-xs font-medium text-slate-500">From</label>
            <input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}" required
                   class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <div>
            <label for="to" class="block text-xs font-medium text-slate-500">To</label>
            <input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}" required
                   class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <div>
            <label for="payment_method" class="block text-xs font-medium text-slate-500">Payment</label>
            <select id="payment_method" name="payment_method" class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm">
                <option value="">Any</option>
                @foreach (['cash' => 'Cash', 'card' => 'Card', 'digital' => 'Digital'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['payment_method'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="status" class="block text-xs font-medium text-slate-500">Status</label>
            <select id="status" name="status" class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm">
                <option value="">Any</option>
                <option value="completed" @selected(($filters['status'] ?? null) === 'completed')>Completed</option>
                <option value="cancelled" @selected(($filters['status'] ?? null) === 'cancelled')>Cancelled</option>
            </select>
        </div>
        <div class="flex items-end gap-2 sm:col-span-2">
            <button type="submit" class="rounded-lg bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">Run report</button>
            <a href="{{ route('admin.reports.sales') }}" class="py-2 text-sm text-slate-500 hover:text-slate-800">Reset</a>
        </div>
    </form>

    <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">Orders</p>
            <p class="mt-1 text-xl font-bold text-slate-900">{{ $totals['orders'] }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">Gross</p>
            <p class="mt-1 text-xl font-bold text-slate-900">{{ $money($totals['gross']) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">Refunded</p>
            <p class="mt-1 text-xl font-bold text-rose-600">{{ $money($totals['refunds']) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">Net</p>
            <p class="mt-1 text-xl font-bold text-slate-900">{{ $money($totals['net']) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">Tendered</p>
            <p class="mt-1 text-xl font-bold text-slate-900">{{ $money($totals['tendered']) }}</p>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Order</th>
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">Cashier</th>
                    <th class="px-4 py-3">Payment</th>
                    <th class="px-4 py-3 text-right">Items</th>
                    <th class="px-4 py-3 text-right">Total</th>
                    <th class="px-4 py-3 text-right">Refunded</th>
                    <th class="px-4 py-3 text-right">Net</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($orders as $order)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2">
                            <a href="{{ route('orders.show', $order) }}" class="font-medium text-emerald-700 hover:underline">{{ $order->order_number }}</a>
                            @if ($order->is_cancelled)
                                <span class="ml-1 rounded-full bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-700">Cancelled</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-slate-500">{{ $order->created_at->format('d M Y H:i') }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ $order->user?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-slate-600">{{ ucfirst($order->payment_method) }}</td>
                        <td class="px-4 py-2 text-right text-slate-600">{{ $order->items_sum_quantity ?? 0 }}</td>
                        <td class="px-4 py-2 text-right text-slate-600">{{ $order->total }}</td>
                        <td class="px-4 py-2 text-right text-rose-600">
                            {{ (float) $order->refunded_total > 0 ? $order->refunded_total : '—' }}
                        </td>
                        <td class="px-4 py-2 text-right font-medium text-slate-900">{{ number_format((float) $order->net_total, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-slate-400">No orders in this date range.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($byDay->isNotEmpty())
        <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-2 font-semibold text-slate-900">By day</h3>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-2 py-1">Day</th>
                        <th class="px-2 py-1 text-right">Orders</th>
                        <th class="px-2 py-1 text-right">Gross</th>
                        <th class="px-2 py-1 text-right">Refunded</th>
                        <th class="px-2 py-1 text-right">Net</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($byDay as $day)
                        <tr>
                            <td class="px-2 py-1.5 text-slate-700">{{ $day['label'] }}</td>
                            <td class="px-2 py-1.5 text-right text-slate-600">{{ $day['orders'] }}</td>
                            <td class="px-2 py-1.5 text-right text-slate-600">{{ $money($day['gross']) }}</td>
                            <td class="px-2 py-1.5 text-right text-rose-600">{{ $money($day['refunds']) }}</td>
                            <td class="px-2 py-1.5 text-right font-medium text-slate-900">{{ $money(max(0, $day['gross'] - $day['refunds'])) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
