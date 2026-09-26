@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        use App\Support\Money;

        $money = fn (int $cents) => $currency.Money::fromCents($cents);
    @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $isAdmin ? 'Shop overview' : 'My sales' }}
            </h2>
            <p class="text-sm text-slate-500">
                Welcome back, {{ $user->name }}.
            </p>
        </div>

        <div class="flex gap-1 rounded-lg bg-slate-200 p-1 text-sm">
            @foreach ($ranges as $value => $label)
                <a href="{{ route('dashboard', ['range' => $value]) }}"
                   @class([
                       'rounded-md px-3 py-1.5 font-medium transition',
                       'bg-white text-slate-900 shadow-sm' => $range === $value,
                       'text-slate-600 hover:text-slate-900' => $range !== $value,
                   ])>
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-500">Net revenue</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">{{ $money($summary['net']) }}</p>
            @if ($summary['refunds'] > 0)
                <p class="mt-1 text-xs text-rose-600">after {{ $money($summary['refunds']) }} refunded</p>
            @endif
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-500">Orders</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">{{ $summary['orders'] }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ $summary['items'] }} items sold</p>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-500">Average order</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">{{ $money($summary['average']) }}</p>
        </div>

        @if ($isAdmin)
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <p class="text-sm text-slate-500">Low stock</p>
                <p class="mt-1 text-2xl font-bold {{ $inventory['lowStock']->isNotEmpty() ? 'text-amber-600' : 'text-slate-900' }}">
                    {{ $inventory['lowStock']->count() }}
                </p>
                <p class="mt-1 text-xs text-slate-500">{{ $inventory['outOfStock'] }} out of stock</p>
            </div>
        @else
            <div class="rounded-xl border border-slate-200 bg-white p-5">
                <p class="text-sm text-slate-500">Role</p>
                <p class="mt-1 text-2xl font-bold text-slate-900">
                    {{ $user->getRoleNames()->first() ?? 'Unassigned' }}
                </p>
            </div>
        @endif
    </div>

    @if ($isAdmin)
        <div class="mt-4 grid gap-4 lg:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
                <div class="border-b border-slate-200 px-4 py-3">
                    <h3 class="font-semibold text-slate-900">Daily sales</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-2">Day</th>
                                <th class="px-4 py-2 text-right">Orders</th>
                                <th class="px-4 py-2 text-right">Net</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @php($peak = max(1, $trend->max('net')))
                            @foreach ($trend as $day)
                                <tr>
                                    <td class="px-4 py-2">
                                        <div class="flex items-center gap-2">
                                            <span class="w-24 text-slate-700">{{ $day['label'] }}</span>
                                            <span class="h-2 rounded-full bg-emerald-400"
                                                  style="width: {{ max(2, round(($day['net'] / $peak) * 100)) }}%"></span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2 text-right text-slate-500">{{ $day['orders'] }}</td>
                                    <td class="px-4 py-2 text-right font-medium text-slate-900">{{ $money($day['net']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-4 py-3">
                    <h3 class="font-semibold text-slate-900">Best sellers</h3>
                </div>
                @if ($bestSellers->isEmpty())
                    <p class="px-4 py-8 text-center text-sm text-slate-400">No sales in this period.</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($bestSellers as $row)
                            <li class="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-slate-800">
                                        @if ($row['product'] && $row['product']->is_active)
                                            <a href="{{ route('products.show', $row['product']) }}" class="hover:underline">{{ $row['name'] }}</a>
                                        @else
                                            {{ $row['name'] }}
                                        @endif
                                    </p>
                                    <p class="text-xs text-slate-400">{{ $row['sku'] }}</p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="font-medium text-slate-900">{{ $row['quantity'] }}</p>
                                    <p class="text-xs text-slate-500">{{ $money($row['revenue']) }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-2 font-semibold text-slate-900">By payment method</h3>
                @if ($byPayment->isEmpty())
                    <p class="py-4 text-center text-sm text-slate-400">No sales in this period.</p>
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
                    <p class="py-4 text-center text-sm text-slate-400">No sales in this period.</p>
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
                <h3 class="mb-2 font-semibold text-slate-900">Inventory</h3>
                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-600">Stock value at cost</dt>
                        <dd class="font-medium text-slate-900">{{ $money($inventory['value']) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-600">Units on hand</dt>
                        <dd class="font-medium text-slate-900">{{ $inventory['units'] }}</dd>
                    </div>
                </dl>

                @if ($inventory['lowStock']->isNotEmpty())
                    <p class="mt-3 text-xs font-medium text-slate-500">Needs restocking</p>
                    <ul class="mt-1 space-y-0.5 text-sm">
                        @foreach ($inventory['lowStock']->take(6) as $product)
                            <li class="flex justify-between">
                                <a href="{{ route('admin.inventory.adjust.create', $product) }}" class="truncate text-slate-700 hover:underline">{{ $product->name }}</a>
                                <span class="{{ $product->stock <= 0 ? 'text-rose-600' : 'text-amber-600' }}">{{ $product->stock }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($inventory['lowStock']->count() > 6)
                        <p class="mt-1 text-xs text-slate-400">and {{ $inventory['lowStock']->count() - 6 }} more</p>
                    @endif
                @endif
            </div>
        </div>
    @endif
@endsection
