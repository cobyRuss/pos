@extends('layouts.app')

@section('title', 'Reports')

@section('content')
    @php
        use App\Support\Money;

        $money = fn (int $cents) => $currency.Money::fromCents($cents);
    @endphp

    <x-page-header title="Reports">
        <a href="{{ route('admin.reports.sales') }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Custom date range
        </a>
        <a href="{{ route('admin.reports.print', ['report' => 'inventory']) }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Print inventory
        </a>
        <a href="{{ route('admin.reports.index', ['range' => $range, 'format' => 'csv']) }}"
           class="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
            Export CSV
        </a>
    </x-page-header>

    <div class="mb-4 flex flex-wrap gap-1 rounded-lg bg-slate-200 p-1 text-sm">
        @foreach ($ranges as $value => $label)
            <a href="{{ route('admin.reports.index', ['range' => $value]) }}"
               @class([
                   'rounded-md px-3 py-1.5 font-medium transition',
                   'bg-white text-slate-900 shadow-sm' => $range === $value,
                   'text-slate-600 hover:text-slate-900' => $range !== $value,
               ])>
                {{ $label }}
            </a>
        @endforeach
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">Net revenue</p>
            <p class="mt-1 text-xl font-bold text-slate-900">{{ $money($totals['net']) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">Orders</p>
            <p class="mt-1 text-xl font-bold text-slate-900">{{ $totals['orders'] }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">Refunded</p>
            <p class="mt-1 text-xl font-bold text-rose-600">{{ $money($totals['refunds']) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-sm text-slate-500">Average order</p>
            <p class="mt-1 text-xl font-bold text-slate-900">{{ $money($totals['average']) }}</p>
        </div>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm lg:col-span-2">
            <h3 class="mb-2 font-semibold text-slate-900">Orders in this period</h3>
            <div class="max-h-96 overflow-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-2 py-2">Order</th>
                            <th class="px-2 py-2">When</th>
                            <th class="px-2 py-2">Payment</th>
                            <th class="px-2 py-2 text-right">Total</th>
                            <th class="px-2 py-2 text-right">Net</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($orders as $order)
                            <tr>
                                <td class="px-2 py-1.5">
                                    <a href="{{ route('orders.show', $order) }}" class="text-emerald-700 hover:underline">{{ $order->order_number }}</a>
                                </td>
                                <td class="px-2 py-1.5 text-slate-500">{{ $order->created_at->format('d M H:i') }}</td>
                                <td class="px-2 py-1.5 text-slate-600">{{ ucfirst($order->payment_method) }}</td>
                                <td class="px-2 py-1.5 text-right text-slate-600">{{ $order->total }}</td>
                                <td class="px-2 py-1.5 text-right font-medium text-slate-900">{{ number_format((float) $order->net_total, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-2 py-8 text-center text-slate-400">No orders in this period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-2 font-semibold text-slate-900">By payment method</h3>
                @if ($byPayment->isEmpty())
                    <p class="py-3 text-center text-sm text-slate-400">Nothing sold yet.</p>
                @else
                    <ul class="space-y-1.5 text-sm">
                        @foreach ($byPayment as $row)
                            <li class="flex justify-between">
                                <span class="text-slate-600">{{ ucfirst($row['method']) }} <span class="text-xs text-slate-400">({{ $row['orders'] }})</span></span>
                                <span class="font-medium text-slate-900">{{ $money($row['net']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-2 font-semibold text-slate-900">By staff member</h3>
                @if ($byStaff->isEmpty())
                    <p class="py-3 text-center text-sm text-slate-400">Nothing sold yet.</p>
                @else
                    <ul class="space-y-1.5 text-sm">
                        @foreach ($byStaff as $row)
                            <li class="flex justify-between">
                                <span class="text-slate-600">{{ $row['name'] }} <span class="text-xs text-slate-400">({{ $row['orders'] }})</span></span>
                                <span class="font-medium text-slate-900">{{ $money($row['net']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-2 font-semibold text-slate-900">Best sellers</h3>
                @if ($bestSellers->isEmpty())
                    <p class="py-3 text-center text-sm text-slate-400">Nothing sold yet.</p>
                @else
                    <ul class="space-y-1.5 text-sm">
                        @foreach ($bestSellers as $row)
                            <li class="flex justify-between gap-2">
                                <span class="min-w-0 truncate text-slate-600">{{ $row['name'] }}</span>
                                <span class="shrink-0 text-slate-500">{{ $row['quantity'] }} &middot; {{ $money($row['revenue']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-2 font-semibold text-slate-900">Inventory</h3>
                <div class="flex justify-between text-sm">
                    <span class="text-slate-600">Stock value at cost</span>
                    <span class="font-medium text-slate-900">{{ $money($inventoryValue) }}</span>
                </div>
                @if ($lowStock->isNotEmpty())
                    <p class="mt-3 text-xs font-medium text-slate-500">Needs restocking</p>
                    <ul class="mt-1 space-y-0.5 text-sm">
                        @foreach ($lowStock as $product)
                            <li class="flex justify-between">
                                <a href="{{ route('admin.inventory.adjust.create', $product) }}" class="truncate text-slate-700 hover:underline">{{ $product->name }}</a>
                                <span class="{{ $product->stock <= 0 ? 'text-rose-600' : 'text-amber-600' }}">{{ $product->stock }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endsection
