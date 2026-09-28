@extends('layouts.app')

@section('title', 'Adjust Stock')
@section('page-title', 'Adjust Stock')
@section('page-subtitle', $product->name.' — current stock: '.$product->stock)

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7">
        @if ($expiredCount > 0)
            <div class="alert alert-danger d-flex align-items-start gap-2">
                <i class="bi bi-exclamation-octagon-fill mt-1"></i>
                <div>
                    <strong>{{ $expiredCount }} lot(s) past the expiry date.</strong>
                    Pick one below to write the units off, or move them to an adjustment.
                </div>
            </div>
        @elseif ($expiringCount > 0)
            <div class="alert alert-warning d-flex align-items-start gap-2">
                <i class="bi bi-clock-fill mt-1"></i>
                <div>
                    <strong>{{ $expiringCount }} lot(s) expiring soon.</strong>
                    Sales draw from the soonest date first.
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-header">Stock Movement</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.inventory.store', $product) }}" id="stock-form"
                      data-current-stock="{{ $product->stock }}">
                    @csrf

                    <div class="d-flex align-items-center gap-3 mb-4 p-3 bg-body-tertiary rounded">
                        <span class="product-thumb" style="width:46px;height:46px;">
                            {{ strtoupper(mb_substr($product->name, 0, 2)) }}
                        </span>
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate">{{ $product->name }}</div>
                            <div class="text-body-secondary small">
                                {{ $product->category?->name ?? 'Uncategorised' }}
                            </div>
                        </div>
                        <div class="ms-auto text-end">
                            <div class="stat-label">Current</div>
                            <div class="fs-4 fw-bold">{{ $product->stock }}</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Movement Type <span class="text-danger">*</span></label>
                        <div class="btn-group w-100" role="group" aria-label="Movement type">
                            @foreach ($types as $value => $label)
                                <input type="radio" class="btn-check" name="type" id="type_{{ $value }}"
                                       value="{{ $value }}" @checked(old('type', 'stock_in') === $value)>
                                <label class="btn btn-outline-primary" for="type_{{ $value }}">{{ $label }}</label>
                            @endforeach
                        </div>
                        @error('type')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>

                    {{-- Quantity: used by stock-in / stock-out --}}
                    <div class="mb-3" id="quantity-field">
                        <label for="quantity" class="form-label">Quantity <span class="text-danger">*</span></label>
                        <input type="number" min="1" step="1" class="form-control @error('quantity') is-invalid @enderror"
                               id="quantity" name="quantity" value="{{ old('quantity', 1) }}">
                        <div class="form-text" id="quantity-hint">Number of units to add.</div>
                        @error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- New level: used by adjustment --}}
                    <div class="mb-3 d-none" id="new-stock-field">
                        <label for="new_stock" class="form-label">New Stock Level <span class="text-danger">*</span></label>
                        <input type="number" min="0" step="1" class="form-control @error('new_stock') is-invalid @enderror"
                               id="new_stock" name="new_stock" value="{{ old('new_stock', $product->stock) }}">
                        <div class="form-text">Sets the absolute stock level. The difference is logged as an adjustment.</div>
                        @error('new_stock')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Lot: which delivery the units belong to. Each lot carries
                         its own expiry date, and a removal with no lot chosen is
                         drawn from the soonest date first. --}}
                    <div class="mb-3">
                        <label for="batch_id" class="form-label">Batch / Lot</label>
                        <select class="form-select @error('batch_id') is-invalid @enderror" id="batch_id" name="batch_id">
                            <option value="" @selected(old('batch_id') === '' || old('batch_id') === null)>
                                Automatic — soonest expiry first
                            </option>
                            @foreach ($batches as $batch)
                                <option value="{{ $batch->id }}" @selected((string) old('batch_id') === (string) $batch->id)>
                                    {{ $batch->displayLabel() }}
                                    @if ($batch->status() === 'expired')
                                        — EXPIRED
                                    @endif
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text" id="batch-hint">
                            Leave on automatic to take units from the lot that expires soonest.
                        </div>
                        @error('batch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- New-lot details: only shown when a stock-in is not being
                         added to an existing lot. --}}
                    <div class="mb-3 d-none" id="new-lot-fields">
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <label for="batch_no" class="form-label">Batch Number</label>
                                <input type="text" maxlength="50" class="form-control @error('batch_no') is-invalid @enderror"
                                       id="batch_no" name="batch_no" value="{{ old('batch_no') }}"
                                       placeholder="e.g. LOT-4412">
                                @error('batch_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-sm-6">
                                <label for="expiry_date" class="form-label">
                                    Expiry Date <span class="text-danger" id="expiry-required">*</span>
                                </label>
                                <input type="date" min="{{ now()->toDateString() }}"
                                       class="form-control @error('expiry_date') is-invalid @enderror"
                                       id="expiry_date" name="expiry_date" value="{{ old('expiry_date') }}">
                                <div class="form-text">Recording the date per delivery is the point of the lot.</div>
                                @error('expiry_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="reason" class="form-label">Reason <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('reason') is-invalid @enderror" id="reason" name="reason"
                               value="{{ old('reason') }}" required maxlength="255"
                               placeholder="e.g. supplier delivery, damaged goods, stock count correction">
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="alert alert-light border d-none" id="preview"></div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Apply Movement</button>
                        <a href="{{ route('inventory.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    const currentStock = parseInt(document.getElementById('stock-form').dataset.currentStock, 10);
    const typeInputs = document.querySelectorAll('input[name="type"]');
    const quantityField = document.getElementById('quantity-field');
    const newStockField = document.getElementById('new-stock-field');
    const quantityInput = document.getElementById('quantity');
    const newStockInput = document.getElementById('new_stock');
    const quantityHint = document.getElementById('quantity-hint');
    const batchSelect = document.getElementById('batch_id');
    const batchHint = document.getElementById('batch-hint');
    const newLotFields = document.getElementById('new-lot-fields');
    const preview = document.getElementById('preview');

    function selectedType() {
        return document.querySelector('input[name="type"]:checked')?.value;
    }

    function hasExplicitBatch() {
        return batchSelect.value !== '';
    }

    function toggle() {
        const type = selectedType();
        const isAdjustment = type === 'adjustment';
        const isStockIn = type === 'stock_in';

        quantityField.classList.toggle('d-none', isAdjustment);
        newStockField.classList.toggle('d-none', !isAdjustment);

        if (isAdjustment) {
            quantityHint.textContent = '';
        } else if (type === 'stock_out') {
            quantityHint.textContent = `Number of units to remove. ${currentStock} currently in stock.`;
        } else {
            quantityHint.textContent = 'Number of units to add.';
        }

        // New-lot details only make sense for a delivery arriving fresh. A
        // stock-out never opens a lot, and adding to a chosen lot already has a
        // date on it.
        const showNewLot = isStockIn && !hasExplicitBatch();
        newLotFields.classList.toggle('d-none', !showNewLot);

        batchHint.textContent = hasExplicitBatch()
            ? 'Units will be taken from the selected lot only.'
            : 'Leave on automatic to take units from the lot that expires soonest.';

        updatePreview();
    }

    function updatePreview() {
        const type = selectedType();
        let after = currentStock;

        if (type === 'adjustment') {
            after = parseInt(newStockInput.value || '0', 10);
        } else {
            const qty = parseInt(quantityInput.value || '0', 10);
            after = type === 'stock_out' ? currentStock - qty : currentStock + qty;
        }

        if (Number.isNaN(after)) after = currentStock;

        const delta = after - currentStock;

        preview.classList.remove('d-none');
        preview.innerHTML = `Stock will change from <strong>${currentStock}</strong> to
            <strong>${after}</strong> (<span class="${delta < 0 ? 'text-danger' : 'text-success'}">${delta > 0 ? '+' : ''}${delta}</span>).`;
    }

    typeInputs.forEach((input) => input.addEventListener('change', toggle));
    batchSelect.addEventListener('change', toggle);
    quantityInput.addEventListener('input', updatePreview);
    newStockInput.addEventListener('input', updatePreview);

    toggle();
})();
</script>
@endpush
