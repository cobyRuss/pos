@extends('layouts.app')

@section('title', 'Batches')
@section('page-title', 'Batches & Expiry')
@section('page-subtitle', $product->name.' — '.$batches->count().' lot(s), '.$product->stock.' unit(s) on hand')

@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="{{ route('products.show', $product) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to product
    </a>
    <a href="{{ route('admin.inventory.create', $product) }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-sliders me-1"></i>Adjust stock
    </a>
</div>

@if ($expiredCount > 0)
    <div class="alert alert-danger d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-octagon-fill"></i>
        <div>
            <strong>{{ $expiredCount }} lot(s) are past their expiry date.</strong>
            These units cannot be sold. Remove them with a stock adjustment, then delete the empty lot.
        </div>
    </div>
@endif

@if ($expiringCount > 0)
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-clock-fill"></i>
        <div>
            <strong>{{ $expiringCount }} lot(s) expire soon.</strong>
            Sales draw from the soonest date first, so these will be used before anything else.
        </div>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex align-items-center">
                Delivery Lots
                <span class="badge text-bg-light ms-2">Oldest expiry first</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Expiry Date</th>
                        <th class="text-center">Units</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($batches as $batch)
                        <tr class="{{ $batch->status() === 'expired' ? 'table-danger' : '' }}">
                            <td>
                                <div class="fw-semibold">{{ $batch->batch_no ?: 'Unlabelled lot' }}</div>
                                @if ($batch->notes)
                                    <div class="small text-body-secondary">{{ $batch->notes }}</div>
                                @endif
                            </td>
                            <td>
                                @if ($batch->hasExpiryDate())
                                    <div>{{ \App\Support\DateFormat::date($batch->expiry_date) }}</div>
                                    <div class="small text-body-secondary">{{ $batch->expiryLabel() }}</div>
                                @else
                                    <span class="text-body-secondary">No expiry</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="badge fs-6 {{ $batch->quantity > 0 ? 'text-bg-primary' : 'text-bg-secondary' }}">
                                    {{ $batch->quantity }}
                                </span>
                            </td>
                            <td>
                                @php
                                    $badge = match ($batch->status()) {
                                        'expired' => 'text-bg-danger',
                                        'expiring' => 'text-bg-warning',
                                        'ok' => 'text-bg-success',
                                        default => 'text-bg-light',
                                    };
                                    $label = match ($batch->status()) {
                                        'expired' => 'Expired',
                                        'expiring' => 'Expiring soon',
                                        'ok' => 'Good',
                                        default => 'No expiry',
                                    };
                                @endphp
                                <span class="badge {{ $badge }}">{{ $label }}</span>
                            </td>
                            <td class="text-end">
                                <div class="d-flex gap-1 justify-content-end">
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            data-bs-toggle="modal" data-bs-target="#editBatch{{ $batch->id }}"
                                            title="Edit expiry date or count">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" action="{{ route('admin.batches.destroy', $batch) }}"
                                          onsubmit="return confirm('Remove this lot? Only empty lots can be removed.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove lot">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-4">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                No lots recorded yet. Add the first delivery below.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Edit dialogs live outside the table: a div inside tbody is not
                 valid markup and browsers reparent it unpredictably. --}}
            @foreach ($batches as $batch)
                <div class="modal fade" id="editBatch{{ $batch->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <form class="modal-content" method="POST"
                              action="{{ route('admin.batches.update', $batch) }}">
                            @csrf
                            @method('PUT')
                            <div class="modal-header">
                                <h5 class="modal-title">Edit {{ $batch->batch_no ?: 'lot' }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label" for="edit_no_{{ $batch->id }}">Batch Number</label>
                                    <input type="text" maxlength="50" class="form-control"
                                           id="edit_no_{{ $batch->id }}" name="batch_no"
                                           value="{{ $batch->batch_no }}">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="edit_exp_{{ $batch->id }}">Expiry Date</label>
                                    <input type="date" class="form-control"
                                           id="edit_exp_{{ $batch->id }}" name="expiry_date"
                                           value="{{ $batch->expiry_date?->toDateString() }}">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="edit_qty_{{ $batch->id }}">Units</label>
                                    <input type="number" min="{{ $batch->quantity }}" step="1" class="form-control"
                                           id="edit_qty_{{ $batch->id }}" name="quantity"
                                           value="{{ $batch->quantity }}">
                                    <div class="form-text">
                                        Can be raised to record a delivery. To reduce it, remove the units with a
                                        stock adjustment first so the change is logged.
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label" for="edit_notes_{{ $batch->id }}">Notes</label>
                                    <textarea class="form-control" rows="2" maxlength="1000"
                                              id="edit_notes_{{ $batch->id }}" name="notes">{{ $batch->notes }}</textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">Save Lot</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach
            <div class="card-footer bg-body-tertiary small text-body-secondary">
                <i class="bi bi-info-circle me-1"></i>
                Stock on this product ({{ $product->stock }}) is the sum of these lots. Sales take from the soonest
                expiry first, and never from a lot that has expired.
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Add a Delivery</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.batches.store', $product) }}">
                    @csrf

                    <div class="mb-3">
                        <label for="batch_no" class="form-label">Batch Number</label>
                        <input type="text" maxlength="50" class="form-control @error('batch_no') is-invalid @enderror"
                               id="batch_no" name="batch_no" value="{{ old('batch_no') }}"
                               placeholder="e.g. LOT-4412">
                        <div class="form-text">Optional — the supplier's own reference.</div>
                        @error('batch_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="expiry_date" class="form-label">
                            Expiry Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" min="{{ now()->toDateString() }}"
                               class="form-control @error('expiry_date') is-invalid @enderror"
                               id="expiry_date" name="expiry_date" value="{{ old('expiry_date') }}">
                        <div class="form-text">Required for a new delivery, so it can be flagged before it lapses.</div>
                        @error('expiry_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="quantity" class="form-label">Units Received <span class="text-danger">*</span></label>
                        <input type="number" min="0" step="1" class="form-control @error('quantity') is-invalid @enderror"
                               id="quantity" name="quantity" value="{{ old('quantity', 1) }}">
                        @error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control @error('notes') is-invalid @enderror" rows="2"
                                  maxlength="1000" id="notes" name="notes"
                                  placeholder="Supplier, storage location…">{{ old('notes') }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-plus-lg me-1"></i>Add Lot
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
