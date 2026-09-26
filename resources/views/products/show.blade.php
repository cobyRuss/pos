@extends('layouts.app')

@section('title', $product->name)

@section('content')
    <a href="{{ route('products.index') }}" class="mb-4 inline-block text-sm text-slate-500 hover:text-slate-800">
        &larr; Back to products
    </a>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="grid aspect-square place-items-center overflow-hidden rounded-xl border border-slate-200 bg-white">
            @if ($product->image_url)
                <img src="{{ $product->image_url }}" alt="{{ $product->name }}" class="h-full w-full object-cover">
            @else
                <span class="text-6xl font-bold text-slate-200">
                    {{ Str::upper(Str::substr($product->name, 0, 2)) }}
                </span>
            @endif
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6">
            <p class="text-sm text-slate-500">{{ $product->category?->name ?? 'Uncategorised' }}</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-900">{{ $product->name }}</h1>

            <p class="mt-2 font-mono text-sm text-slate-400">
                SKU {{ $product->sku }}
                @if ($product->barcode)
                    &middot; Barcode {{ $product->barcode }}
                @endif
            </p>

            @if ($product->description)
                <p class="mt-4 text-sm leading-relaxed text-slate-600">{{ $product->description }}</p>
            @endif

            <dl class="mt-6 space-y-2 border-t border-slate-100 pt-4 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-500">Price</dt>
                    <dd class="font-semibold text-slate-900">
                        {{ \App\Models\Setting::get('currency_symbol', '$') }}{{ number_format($product->price, 2) }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-slate-500">Availability</dt>
                    <dd class="font-semibold text-slate-900">
                        @if ($product->is_out_of_stock)
                            <span class="text-rose-600">Out of stock</span>
                        @else
                            {{ $product->stock }} in stock
                            @if ($product->is_low_stock)
                                <span class="text-amber-600">(low)</span>
                            @endif
                        @endif
                    </dd>
                </div>
            </dl>

            @can('products.manage')
                <a href="{{ route('admin.products.edit', $product) }}"
                   class="mt-6 inline-block rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Edit product
                </a>
            @endcan
        </div>
    </div>
@endsection
