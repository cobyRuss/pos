@extends('layouts.app')

@section('title', 'Inventory')

@section('content')
    <x-page-header title="Inventory">
        @if ($canAdjust)
            <a href="{{ route('inventory.logs') }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Movement log
            </a>
        @endif
    </x-page-header>

    @unless ($canAdjust)
        <p class="mt-1 text-sm text-slate-500">Read-only. Ask an administrator to adjust stock.</p>
    @endunless

    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Products</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($totals['products']) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Units on hand</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($totals['units']) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Stock value</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">
                {{ \App\Models\Setting::get('currency_symbol', '$') }}{{ number_format($totals['stock_value'], 2) }}
            </p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Needs attention</p>
            <p class="mt-1 text-2xl font-bold">
                <span class="text-amber-600">{{ $totals['low_stock'] }}</span>
                <span class="text-sm font-medium text-slate-400">low</span>
                <span class="ml-2 text-rose-600">{{ $totals['out_of_stock'] }}</span>
                <span class="text-sm font-medium text-slate-400">out</span>
            </p>
        </div>
    </div>

    <form method="GET" action="{{ route('inventory.index') }}"
          class="mb-4 mt-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-text-input name="search" label="Search" placeholder="Name, SKU or barcode"
                      value="{{ $filters['search'] ?? '' }}" />

        <x-select-input name="category" label="Category" :value="$filters['category'] ?? null"
                        :options="$categories->pluck('name', 'id')" include-blank blank-label="All categories" />

        <x-select-input name="status" label="Status" :value="$filters['status'] ?? null"
                        :options="['low_stock' => 'Low stock', 'out_of_stock' => 'Out of stock']"
                        include-blank blank-label="Any" />

        <div class="flex items-end gap-2">
            <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                Filter
            </button>
            @if (array_filter($filters))
                <a href="{{ route('inventory.index') }}"
                   class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
                    Reset
                </a>
            @endif
        </div>
    </form>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Product</th>
                    <th class="px-4 py-3">Category</th>
                    <th class="px-4 py-3 text-right">On hand</th>
                    <th class="px-4 py-3 text-right">Threshold</th>
                    <th class="px-4 py-3">Status</th>
                    @if ($canAdjust)
                        <th class="px-4 py-3 text-right">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($products as $product)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <p class="font-medium text-slate-900">{{ $product->name }}</p>
                            <p class="font-mono text-xs text-slate-400">{{ $product->sku }}</p>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $product->category?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <span @class([
                                'font-semibold',
                                'text-rose-600' => $product->is_out_of_stock,
                                'text-amber-600' => ! $product->is_out_of_stock && $product->is_low_stock,
                                'text-slate-700' => ! $product->is_low_stock,
                            ])>{{ $product->stock }}</span>
                        </td>
                        <td class="px-4 py-3 text-right text-slate-500">{{ $product->low_stock_threshold }}</td>
                        <td class="px-4 py-3">
                            @if ($product->is_out_of_stock)
                                <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">Out of stock</span>
                            @elseif ($product->is_low_stock)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">Low stock</span>
                            @else
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">OK</span>
                            @endif
                        </td>
                        @if ($canAdjust)
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    @if ($product->tracks_expiry)
                                        <a href="{{ route('admin.lots.index', $product) }}"
                                           class="font-medium text-emerald-700 hover:text-emerald-900">Batches</a>
                                    @endif
                                    <a href="{{ route('admin.inventory.adjust.create', $product) }}"
                                       class="font-medium text-emerald-700 hover:text-emerald-900">Adjust</a>
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $canAdjust ? 6 : 5 }}" class="px-4 py-10 text-center text-slate-500">
                            No products matched your filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $products->links() }}</div>
@endsection
