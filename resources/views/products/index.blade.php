@extends('layouts.app')

@section('title', 'Products')

@section('content')
    <form method="GET" action="{{ route('products.index') }}"
          class="mb-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-text-input name="search" label="Search" placeholder="Name, SKU or barcode"
                      value="{{ $filters['search'] ?? '' }}" />

        <x-select-input name="category" label="Category" :value="$filters['category'] ?? null"
                        :options="$categories->pluck('name', 'id')" include-blank blank-label="All categories" />

        <x-select-input name="availability" label="Availability" :value="$filters['availability'] ?? null"
                        :options="['in_stock' => 'In stock']" include-blank blank-label="Any" />

        <div class="flex items-end gap-2">
            <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                Filter
            </button>
            @if (array_filter($filters))
                <a href="{{ route('products.index') }}"
                   class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
                    Reset
                </a>
            @endif
        </div>
    </form>

    <p class="mb-3 text-sm text-slate-500">
        {{ $products->total() }} {{ Str::plural('product', $products->total()) }} found
    </p>

    @if ($products->isEmpty())
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">
            No products matched your search.
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($products as $product)
                <a href="{{ route('products.show', $product) }}"
                   class="flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white transition hover:border-emerald-400 hover:shadow-md">
                    <div class="grid aspect-[4/3] place-items-center overflow-hidden bg-slate-100">
                        @if ($product->image_url)
                            <img src="{{ $product->image_url }}" alt="{{ $product->name }}"
                                 class="h-full w-full object-cover" loading="lazy">
                        @else
                            <span class="text-3xl font-bold text-slate-300">{{ Str::upper(Str::substr($product->name, 0, 2)) }}</span>
                        @endif
                    </div>

                    <div class="flex flex-1 flex-col p-4">
                        <p class="text-xs text-slate-400">{{ $product->category?->name ?? 'Uncategorised' }}</p>
                        <h3 class="mt-0.5 font-semibold text-slate-900">{{ $product->name }}</h3>
                        <p class="mt-0.5 font-mono text-xs text-slate-400">{{ $product->sku }}</p>

                        <div class="mt-3 flex items-end justify-between">
                            <p class="text-lg font-bold text-slate-900">
                                {{ \App\Models\Setting::get('currency_symbol', '$') }}{{ number_format($product->price, 2) }}
                            </p>
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-semibold',
                                'bg-rose-100 text-rose-700' => $product->is_out_of_stock,
                                'bg-amber-100 text-amber-700' => ! $product->is_out_of_stock && $product->is_low_stock,
                                'bg-emerald-100 text-emerald-700' => ! $product->is_low_stock,
                            ])>
                                {{ $product->is_out_of_stock ? 'Out of stock' : $product->stock.' in stock' }}
                            </span>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-6">{{ $products->links() }}</div>
    @endif
@endsection
