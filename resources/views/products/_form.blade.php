@php
    /** @var \App\Models\Product $product */
    $editing = $product->exists;
@endphp

<div class="row g-3">
    <div class="col-md-3">
        <div class="mb-3">
            <label for="image" class="form-label">Product Image</label>
            <div class="text-center mb-2">
                @if ($product->hasImage())
                    <img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}"
                         id="imagePreview"
                         class="img-thumbnail rounded-3"
                         style="width:100%;max-width:180px;aspect-ratio:1/1;object-fit:cover;"
                         data-existing="1">
                @else
                    <img src="" alt=""
                         id="imagePreview"
                         class="img-thumbnail rounded-3 d-none"
                         style="width:100%;max-width:180px;aspect-ratio:1/1;object-fit:cover;">
                @endif
            </div>
            <input type="file" class="form-control form-control-sm @error('image') is-invalid @enderror"
                   id="image" name="image" accept="image/jpeg,image/png,image/webp">
            <div class="form-text">JPG, PNG or WebP up to 2&nbsp;MB.</div>
            @error('image')<div class="invalid-feedback">{{ $message }}</div>@enderror

            @if ($product->hasImage())
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="remove_image" value="1" id="remove_image"
                           @checked(old('remove_image'))>
                    <label class="form-check-label small" for="remove_image">Remove current image</label>
                </div>
            @endif
        </div>
    </div>

    <div class="col-md-9">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="category_id" class="form-label">Category</label>
                    <select class="form-select @error('category_id') is-invalid @enderror" id="category_id" name="category_id">
                        <option value="">Uncategorised</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(old('category_id', $product->category_id) == $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="col-md-6">
                <div class="mb-3">
                    <label for="name" class="form-label">Product Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                           value="{{ old('name', $product->name) }}" required maxlength="255">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="barcode" class="form-label">Barcode</label>
                    <input type="text" class="form-control @error('barcode') is-invalid @enderror"
                           id="barcode" name="barcode" inputmode="numeric" maxlength="32"
                           value="{{ old('barcode', $product->barcode) }}" placeholder="e.g. 5012345678900">
                    @error('barcode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">
                        Leave blank if the pack has no printed code. The till can
                        always find the product by name, whether or not this is filled in.
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-12">
                <div class="mb-3">
                    <label for="description" class="form-label">Description</label>
                    <textarea class="form-control @error('description') is-invalid @enderror" id="description"
                              name="description" rows="3" maxlength="2000">{{ old('description', $product->description) }}</textarea>
                    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="mb-3">
            <label for="unit" class="form-label">Unit <span class="text-danger">*</span></label>
            <input type="text" class="form-control @error('unit') is-invalid @enderror" id="unit" name="unit"
                   value="{{ old('unit', $product->unit ?? 'pcs') }}" required maxlength="20">
            <div class="form-text">pcs, kg, L, box…</div>
            @error('unit')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-3">
        <div class="mb-3">
            <label for="cost_price" class="form-label">Cost Price <span class="text-danger">*</span></label>
            <input type="number" step="0.01" min="0" class="form-control @error('cost_price') is-invalid @enderror"
                   id="cost_price" name="cost_price" value="{{ old('cost_price', $product->cost_price ?? 0) }}" required>
            @error('cost_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="col-md-3">
        <div class="mb-3">
            <label for="selling_price" class="form-label">Selling Price <span class="text-danger">*</span></label>
            <input type="number" step="0.01" min="0" class="form-control @error('selling_price') is-invalid @enderror"
                   id="selling_price" name="selling_price" value="{{ old('selling_price', $product->selling_price) }}" required>
            @error('selling_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="col-md-3">
        <div class="mb-3">
            <label for="stock" class="form-label">
                {{ $editing ? 'Stock Level' : 'Opening Stock' }} <span class="text-danger">*</span>
            </label>
            <input type="number" min="0" step="1" class="form-control @error('stock') is-invalid @enderror"
                   id="stock" name="stock" value="{{ old('stock', $product->stock ?? 0) }}" required>
            @if ($editing)
                <div class="form-text">Changes are logged in the stock movement history.</div>
            @else
                <div class="form-text">Recorded as the first delivery lot.</div>
            @endif
            @error('stock')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="col-md-3">
        <div class="mb-3">
            @if ($editing)
                {{-- A product can hold several lots, each with its own date, so
                     one field would be ambiguous here. Point at the lots page. --}}
                <span class="form-label d-block">Expiry Dates</span>
                <a href="{{ route('admin.batches.index', $product) }}" class="btn btn-sm btn-outline-secondary w-100">
                    <i class="bi bi-layers me-1"></i>Manage {{ $product->batches->count() }} lot(s)
                </a>
                <div class="form-text">Each delivery carries its own date.</div>
            @else
                <label for="expiry_date" class="form-label">
                    Expiry Date <span class="text-danger">*</span>
                </label>
                <input type="date" min="{{ now()->toDateString() }}"
                       class="form-control @error('expiry_date') is-invalid @enderror"
                       id="expiry_date" name="expiry_date" value="{{ old('expiry_date') }}" required>
                <div class="form-text">Date on the opening delivery. Leave undated only for non-perishables.</div>
                @error('expiry_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            @endif
        </div>
    </div>

    <div class="col-md-3">
        <div class="mb-3">
            <label for="low_stock_threshold" class="form-label">Low Stock Alert At <span class="text-danger">*</span></label>
            <input type="number" min="0" step="1" class="form-control @error('low_stock_threshold') is-invalid @enderror"
                   id="low_stock_threshold" name="low_stock_threshold"
                   value="{{ old('low_stock_threshold', $product->low_stock_threshold ?? 5) }}" required>
            @error('low_stock_threshold')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</div>

<div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
           @checked(old('is_active', $product->is_active ?? true))>
    <label class="form-check-label" for="is_active">
        Active &mdash; available for sale at the point of sale
    </label>
    @error('is_active')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>

@push('scripts')
<script>
(function () {
    'use strict';

    const input = document.getElementById('image');
    const preview = document.getElementById('imagePreview');
    if (!input || !preview) return;

    const remove = document.getElementById('remove_image');

    input.addEventListener('change', function () {
        const file = input.files && input.files[0];
        if (!file) return;

        preview.src = URL.createObjectURL(file);
        preview.classList.remove('d-none');

        // Picking a new photo means keeping it, so clear the remove flag.
        if (remove) remove.checked = false;
    });

    if (remove) {
        remove.addEventListener('change', function () {
            if (!remove.checked) return;

            preview.classList.add('d-none');
            input.value = '';
        });
    }
})();
</script>
@endpush
