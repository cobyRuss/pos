@extends('layouts.app')

@section('title', 'POS Terminal')

@section('content')
    @inject('cart', 'App\Services\Cart')

    @php
        $cartLines = $cart->lines();
        $subtotal = $cart->subtotal();
        $discountAmount = $cart->discountAmount();
        $taxAmount = $cart->taxAmount($taxRate);
        $total = $cart->total($taxRate);
        $currency = \App\Models\Setting::currency();
        $money = fn (int $cents) => $currency.\App\Support\Money::fromCents($cents);

        // Only needed to decide whether to offer the expired-stock override.
        $productIds = $cartLines->isEmpty()
            ? collect()
            : \App\Models\Product::whereIn('id', $cartLines->pluck('product_id'))->get()->keyBy('id');
    @endphp

    <div class="grid gap-4 xl:grid-cols-3">
        {{-- Product picker --}}
        <div class="xl:col-span-2">
            <form method="GET" action="{{ route('pos.index') }}" class="mb-4 flex flex-wrap items-end gap-2">
                <div class="w-full flex-1 sm:w-64">
                    <x-text-input name="q" type="search" :value="request('q')" placeholder="Search name or SKU…" />
                </div>
                <div>
                    <select name="category" class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">All categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(request('category') == $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="rounded-lg bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
                    Search
                </button>
                @if (request()->hasAny(['q', 'category']))
                    <a href="{{ route('pos.index') }}" class="py-2 text-sm text-slate-500 hover:text-slate-800">Reset</a>
                @endif
            </form>

            @if ($products->isEmpty())
                <div class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">
                    No active products match. @can('products.manage')
                        <a href="{{ route('admin.products.create') }}" class="font-medium text-emerald-700 hover:underline">Add a product</a>.
                    @endcan
                </div>
            @else
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    @foreach ($products as $product)
                        <div class="flex flex-col rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                            <div class="flex aspect-square items-center justify-center overflow-hidden rounded-lg bg-slate-100">
                                @if ($product->image_url)
                                    <img src="{{ $product->image_url }}" alt="{{ $product->name }}" class="h-full w-full object-cover">
                                @else
                                    <span class="text-3xl font-bold text-slate-300">{{ Str::upper(Str::substr($product->name, 0, 1)) }}</span>
                                @endif
                            </div>

                            <p class="mt-2 line-clamp-2 text-sm font-medium text-slate-800" title="{{ $product->name }}">{{ $product->name }}</p>
                            <p class="text-xs text-slate-400">{{ $product->sku }}</p>
                            <p class="mt-1 text-sm font-semibold text-slate-900">{{ $currency }}{{ $product->price }}</p>

                            @if ($product->is_out_of_stock)
                                <span class="mt-2 rounded-md bg-rose-100 px-2 py-1 text-center text-xs font-semibold text-rose-700">Out of stock</span>
                            @else
                                @if ($product->tracks_expiry && $product->next_lot)
                                    @php($days = $product->next_lot->days_until_expiry)
                                    @if ($product->next_lot->is_expired)
                                        <span class="mb-2 rounded-md bg-rose-100 px-2 py-1 text-center text-xs font-semibold text-rose-700">Only expired stock</span>
                                    @elseif ($days !== null && $days <= $product->expiry_warning_days)
                                        <span class="mb-2 rounded-md bg-amber-100 px-2 py-1 text-center text-xs font-semibold text-amber-700">
                                            Use by {{ $product->next_lot->expires_at->format('d M') }}
                                        </span>
                                    @endif
                                @endif

                                @if ($product->is_low_stock)
                                    <span class="mb-2 rounded-md bg-amber-100 px-2 py-1 text-center text-xs font-semibold text-amber-700">
                                        Only {{ $product->stock }} left
                                    </span>
                                @else
                                    <span class="mb-2 rounded-md bg-slate-100 px-2 py-1 text-center text-xs text-slate-500">
                                        {{ $product->stock }} in stock
                                    </span>
                                @endif

                                <form method="POST" action="{{ route('pos.cart.store') }}">
                                    @csrf
                                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                                    <input type="hidden" name="quantity" value="1">
                                    <button type="submit" class="w-full rounded-lg bg-emerald-500 px-3 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
                                        Add
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="mt-4">{{ $products->links() }}</div>
            @endif
        </div>

        {{-- Basket --}}
        <div class="xl:col-span-1">
            <div class="sticky top-4 rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h2 class="font-semibold text-slate-900">Current Sale</h2>
                    @if (! $cart->isEmpty())
                        <form method="POST" action="{{ route('pos.cart.clear') }}"
                              onsubmit="return confirm('Clear the whole cart?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs font-medium text-rose-600 hover:underline">Clear</button>
                        </form>
                    @endif
                </div>

                @if ($cart->isEmpty())
                    <p class="px-4 py-10 text-center text-sm text-slate-400">Cart is empty. Tap a product to begin.</p>
                @else
                    <ul class="max-h-96 divide-y divide-slate-100 overflow-y-auto">
                        @foreach ($cartLines as $line)
                            <li class="px-4 py-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-slate-800">{{ $line['name'] }}</p>
                                        <p class="text-xs text-slate-400">{{ $line['sku'] }} &middot; {{ $money($line['unit_price']) }} each</p>
                                    </div>
                                    <p class="shrink-0 text-sm font-semibold text-slate-900">{{ $money($line['line_total']) }}</p>
                                </div>

                                <div class="mt-2 flex items-center gap-2">
                                    <form method="POST" action="{{ route('pos.cart.update', $line['product_id']) }}" class="flex items-center gap-1">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="product_id" value="{{ $line['product_id'] }}">
                                        <input type="number" name="quantity" value="{{ $line['quantity'] }}" min="0"
                                               class="w-16 rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                        <button type="submit" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-50">Set</button>
                                    </form>

                                    @if ($line['discount_amount'] > 0)
                                        <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-700">
                                            &minus;{{ $money($line['discount_amount']) }}
                                        </span>
                                    @endif

                                    <form method="POST" action="{{ route('pos.cart.destroy', $line['product_id']) }}" class="ml-auto">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs text-rose-600 hover:underline">Remove</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <div class="border-t border-slate-200 px-4 py-3 text-sm">
                        <form method="POST" action="{{ route('pos.cart.discount') }}" class="mb-3 rounded-lg bg-slate-50 p-2">
                            @csrf
                            <label class="mb-1 block text-xs font-medium text-slate-500">Cart discount</label>
                            <div class="flex gap-1">
                                <select name="discount_type" class="w-28 rounded-lg border-slate-300 text-xs shadow-sm">
                                    @foreach ([\App\Services\Cart::DISCOUNT_NONE, \App\Services\Cart::DISCOUNT_FIXED, \App\Services\Cart::DISCOUNT_PERCENT] as $option)
                                        <option value="{{ $option }}" @selected($cart->discount()['type'] === $option)>
                                            {{ ucfirst($option) }}
                                        </option>
                                    @endforeach
                                </select>
                                <input type="number" name="discount_value" step="0.01" min="0"
                                       value="{{ $cart->discount()['value'] }}"
                                       class="w-24 rounded-lg border-slate-300 text-xs shadow-sm">
                                <button type="submit" class="rounded-lg bg-slate-800 px-2 py-1 text-xs font-medium text-white">Apply</button>
                            </div>
                        </form>

                        <dl class="space-y-1">
                            <div class="flex justify-between">
                                <dt class="text-slate-500">Subtotal</dt>
                                <dd class="font-medium">{{ $money($subtotal) }}</dd>
                            </div>
                            @if ($discountAmount > 0)
                                <div class="flex justify-between text-emerald-700">
                                    <dt>Discount</dt>
                                    <dd class="font-medium">&minus;{{ $money($discountAmount) }}</dd>
                                </div>
                            @endif
                            @if ($taxAmount > 0)
                                <div class="flex justify-between">
                                    <dt class="text-slate-500">Tax ({{ rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.') }}%)</dt>
                                    <dd class="font-medium">{{ $money($taxAmount) }}</dd>
                                </div>
                            @endif
                            <div class="flex justify-between border-t border-slate-200 pt-2 text-base">
                                <dt class="font-semibold text-slate-900">Total</dt>
                                <dd class="font-bold text-slate-900">{{ $money($total) }}</dd>
                            </div>
                        </dl>

                        <form method="POST" action="{{ route('pos.checkout') }}" class="mt-3 space-y-2 rounded-lg bg-slate-50 p-2">
                            @csrf
                            <label class="block text-xs font-medium text-slate-500">Payment</label>
                            <select name="payment_method" required
                                    class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                <option value="cash" selected>Cash</option>
                                <option value="card">Card</option>
                                <option value="digital">Digital / e-wallet</option>
                            </select>
                            <input type="number" name="amount_paid" step="0.01" min="0" required
                                   value="{{ old('amount_paid', \App\Support\Money::fromCents($total)) }}"
                                   placeholder="Amount tendered" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
                            <input type="text" name="reference_no" value="{{ old('reference_no') }}"
                                   placeholder="Reference no. (digital payments)"
                                   class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
                            <input type="text" name="walkin_customer_name" value="{{ old('walkin_customer_name') }}"
                                   placeholder="Customer name (optional)" class="w-full rounded-lg border-slate-300 text-sm shadow-sm">
                            @can('inventory.sell-expired')
                                @if ($cart->lines()->contains(fn ($line) => ($productIds[$line['product_id']] ?? false) && $productIds[$line['product_id']]->has_expired_stock))
                                    <label class="flex items-start gap-2 rounded-md bg-rose-50 px-2 py-1.5 text-xs text-rose-800">
                                        <input type="checkbox" name="allow_expired" value="1" class="mt-0.5 rounded border-rose-300 text-rose-600 focus:ring-rose-500">
                                        <span>Basket contains expired stock. Tick to sell it anyway (markdown or write-off).</span>
                                    </label>
                                @endif
                            @endcan
                            <button type="submit" class="w-full rounded-lg bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
                                Complete Sale &middot; {{ $money($total) }}
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
