@extends('layouts.app')

@section('title', 'Products')

@section('content')
    <x-page-header title="Products">
        <a href="{{ route('admin.products.create') }}"
           class="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
            New product
        </a>
    </x-page-header>

    <form method="GET" action="{{ route('admin.products.index') }}"
          class="mb-4 mt-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-text-input name="search" label="Search" placeholder="Name, SKU or barcode"
                      value="{{ $filters['search'] ?? '' }}" />

        <x-select-input name="category" label="Category" :value="$filters['category'] ?? null"
                        :options="$categories->pluck('name', 'id')" include-blank blank-label="All categories" />

        <x-select-input name="status" label="Status" :value="$filters['status'] ?? null"
                        :options="[
                            'active' => 'Active',
                            'inactive' => 'Archived',
                            'low_stock' => 'Low stock',
                            'out_of_stock' => 'Out of stock',
                        ]"
                        include-blank blank-label="Any" />

        <div class="flex items-end gap-2">
            <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                Filter
            </button>
            @if (array_filter($filters))
                <a href="{{ route('admin.products.index') }}"
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
                    <th class="px-4 py-3 text-right">Price</th>
                    <th class="px-4 py-3 text-right">Cost</th>
                    <th class="px-4 py-3 text-right">Stock</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($products as $product)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="h-10 w-10 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                                    @if ($product->image_url)
                                        <img src="{{ $product->image_url }}" alt="" class="h-full w-full object-cover">
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-slate-900">{{ $product->name }}</p>
                                    <p class="font-mono text-xs text-slate-400">{{ $product->sku }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $product->category?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-right font-medium">{{ number_format($product->price, 2) }}</td>
                        <td class="px-4 py-3 text-right text-slate-500">
                            {{ $product->cost !== null ? number_format($product->cost, 2) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <span @class([
                                'font-semibold',
                                'text-rose-600' => $product->is_out_of_stock,
                                'text-amber-600' => ! $product->is_out_of_stock && $product->is_low_stock,
                                'text-slate-700' => ! $product->is_low_stock,
                            ])>{{ $product->stock }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @if (! $product->is_active)
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">Archived</span>
                            @elseif ($product->is_out_of_stock)
                                <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">Out of stock</span>
                            @elseif ($product->is_low_stock)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">Low stock</span>
                            @else
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Active</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-3 text-sm">
                                <a href="{{ route('admin.inventory.adjust.create', $product) }}"
                                   class="font-medium text-slate-600 hover:text-slate-900">Adjust</a>
                                <a href="{{ route('admin.products.edit', $product) }}"
                                   class="font-medium text-emerald-700 hover:text-emerald-900">Edit</a>

                                @if ($product->is_active)
                                    <form method="POST" action="{{ route('admin.products.destroy', $product) }}"
                                          onsubmit="return confirm('Archive {{ $product->name }}? It will be hidden from the POS terminal.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="font-medium text-rose-600 hover:text-rose-800">Archive</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-slate-500">
                            No products matched your filters.
                            <a href="{{ route('admin.products.create') }}" class="font-medium text-emerald-700 hover:underline">
                                Create the first product
                            </a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $products->links() }}</div>
@endsection
