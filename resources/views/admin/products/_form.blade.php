@php($editing = $product->exists)

<form method="POST" enctype="multipart/form-data"
      action="{{ $editing ? route('admin.products.update', $product) : route('admin.products.store') }}"
      class="space-y-6">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    <div class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-base font-semibold text-slate-900">Details</h2>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-text-input name="name" label="Product name" :value="$product->name" required />
            </div>

            <x-select-input name="category_id" label="Category" :value="$product->category_id"
                            :options="$categories->pluck('name', 'id')"
                            include-blank blank-label="Uncategorised" />

            <x-text-input name="sku" label="SKU" :value="$product->sku" required
                          hint="Internal stock code, unique across the catalogue." maxlength="64" />

            <x-text-input name="barcode" label="Barcode" :value="$product->barcode"
                          hint="Optional. Scanned at checkout; EAN-8, UPC-A, EAN-13 or ITF-14." maxlength="64" />

            <div class="sm:col-span-2">
                <x-textarea-input name="description" label="Description" :value="$product->description" rows="3" />
            </div>
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-base font-semibold text-slate-900">Pricing</h2>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <x-text-input name="price" label="Selling price" :value="$product->price ?? old('price')"
                          type="number" step="0.01" min="0" required />

            <x-text-input name="cost" label="Cost price" :value="$product->cost"
                          type="number" step="0.01" min="0"
                          hint="Optional. Used for margin reporting; cannot exceed the selling price." />
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-base font-semibold text-slate-900">Inventory</h2>
        <p class="mt-1 text-sm text-slate-500">
            Changes here are written to the stock movement log with your name against them.
        </p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <x-text-input name="stock" label="Stock on hand" :value="$product->stock ?? old('stock', 0)"
                          type="number" step="1" min="0" required
                          :hint="$editing ? 'Current level: '.$product->stock.'. Saving a different number records an adjustment.' : 'Recorded as the opening stock.'" />

            <x-text-input name="low_stock_threshold" label="Low stock threshold"
                          :value="$product->low_stock_threshold ?? old('low_stock_threshold', 5)"
                          type="number" step="1" min="0" required
                          hint="Alerts once stock reaches this number." />
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-base font-semibold text-slate-900">Image &amp; status</h2>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <label for="image" class="block text-sm font-medium text-slate-700">Product image</label>
                <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-3 file:py-1 file:text-sm">
                <p class="mt-1 text-xs text-slate-500">JPG, PNG or WebP, up to 2 MB.</p>
                @error('image')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-3">
                @if ($editing && $product->image)
                    <div class="flex items-center gap-3">
                        <img src="{{ $product->image_url }}" alt="" class="h-16 w-16 rounded-lg object-cover">
                        <x-checkbox name="remove_image" label="Remove current image" />
                    </div>
                @endif

                <x-checkbox name="is_active" label="Active"
                            :checked="$product->exists ? $product->is_active : true"
                            hint="Inactive products are hidden from the POS terminal." />

                <div class="mt-3">
                    <x-checkbox name="tracks_expiry" label="Track expiry by batch"
                                :checked="$product->exists ? $product->tracks_expiry : false"
                                hint="Sell short-dated batches first (FEFO) and block expired stock. Turn this on for perishable goods only." />

                    @if ($product->exists && $product->tracks_expiry)
                        <div class="mt-2">
                            <label for="expiry_warning_days" class="block text-sm text-slate-600">Warn this many days before expiry</label>
                            <input type="number" id="expiry_warning_days" name="expiry_warning_days" min="1" max="90"
                                   value="{{ old('expiry_warning_days', $product->expiry_warning_days ?? \App\Models\Product::DEFAULT_EXPIRY_WARNING_DAYS) }}"
                                   class="mt-1 w-28 rounded-lg border-slate-300 text-sm shadow-sm">
                            <p class="mt-1 text-xs text-slate-500">
                                The window in which the till should be selling the product down, not the
                                point at which it becomes unsafe. Defaults to {{ \App\Models\Product::DEFAULT_EXPIRY_WARNING_DAYS }} days.
                            </p>
                            <a href="{{ route('admin.lots.index', $product) }}" class="mt-1 inline-block text-sm text-emerald-700 hover:underline">Manage batches</a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="flex items-center justify-end gap-3">
        <a href="{{ route('admin.products.index') }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
            Cancel
        </a>
        <button type="submit"
                class="rounded-lg bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
            {{ $editing ? 'Save changes' : 'Create product' }}
        </button>
    </div>
</form>
