@extends('layouts.app')

@section('title', 'Refund '.$order->order_number)
@section('page-title', 'Create Refund')
@section('page-subtitle', 'Order '.$order->order_number.' — '.\App\Models\Setting::money($order->total))

@section('content')
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="{{ route('orders.show', $order) }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Order
    </a>
    @if ($refundTotal > 0)
        <span class="badge text-bg-warning align-self-center">
            {{ \App\Models\Setting::money($refundTotal) }} already refunded
        </span>
    @endif
    @if ($dailyLimit > 0)
        @php $remaining = max(0, $dailyLimit - $spentToday); @endphp
        <span class="badge {{ $remaining > 0 ? 'text-bg-light text-body-secondary' : 'text-bg-danger' }} align-self-center">
            Your refund allowance today: {{ \App\Models\Setting::money($remaining) }}
            <span class="text-body-secondary">({{ \App\Models\Setting::money($spentToday) }} of {{ \App\Models\Setting::money($dailyLimit) }})</span>
        </span>
    @endif
</div>

@if ($refundableItems->isEmpty())
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Every item on this order has already been refunded.
    </div>
@else
    <form method="POST" action="{{ route('refunds.store', $order) }}" id="refund-form"
          data-currency="{{ \App\Models\Setting::currency() }}"
          data-threshold="{{ $reviewThreshold }}">
        @csrf

        {{-- Replay guard. A double-clicked submit, a retried request, or a flaky
             connection all carry the same key, so the server collapses them onto
             the one refund instead of paying out twice. --}}
        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-header">Select items to refund</div>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Unit price</th>
                                <th class="text-center">Sold</th>
                                <th class="text-center">Refunded</th>
                                <th class="text-center">Available</th>
                                <th class="text-center" style="width: 140px;">Refund qty</th>
                                <th class="text-end">Amount</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($refundableItems as $item)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $item->product_name }}</div>
                                        @if ($item->tax_amount > 0)
                                            @php $unitGross = $item->taxInclusiveUnitTotal(); @endphp
                                            <div class="text-body-secondary small">
                                                incl. {{ \App\Models\Setting::money($item->tax_amount) }} tax —
                                                {{ \App\Models\Setting::money($unitGross) }}/unit
                                            </div>
                                        @endif
                                        @if ($item->product && ! $item->product->is_active)
                                            <div class="text-body-secondary small">product inactive</div>
                                        @endif
                                        @error("items.{{ $item->id }}")
                                            <div class="text-danger small">{{ $message }}</div>
                                        @enderror
                                    </td>
                                    <td class="text-end money">{{ \App\Models\Setting::money($item->unit_price) }}</td>
                                    <td class="text-center">{{ $item->quantity }}</td>
                                    <td class="text-center">{{ $item->refunded_quantity }}</td>
                                    <td class="text-center">
                                        <span class="badge text-bg-light text-body-secondary">{{ $item->refundable_quantity }}</span>
                                    </td>
                                    <td class="text-center">
                                        <input type="number" class="form-control form-control-sm text-center js-qty"
                                               name="items[{{ $item->id }}]" value="{{ old("items.{$item->id}", 0) }}"
                                               min="0" max="{{ $item->refundable_quantity }}" step="1"
                                               data-price="{{ $item->taxInclusiveUnitTotal() }}">
                                    </td>
                                    <td class="text-end money js-line-total">
                                        {{ \App\Models\Setting::money(0) }}
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
                        <button type="button" class="btn btn-sm btn-outline-primary js-max-all">
                            <i class="bi bi-check2-all me-1"></i>Refund everything
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary js-clear-all">Clear</button>
                        <span class="ms-auto fw-semibold">
                            Refund total: <span class="js-total money">{{ \App\Models\Setting::money(0) }}</span>
                        </span>
                    </div>
                </div>

                <div class="alert alert-danger d-none js-over-threshold">
                    <i class="bi bi-exclamation-octagon me-1"></i>
                    <strong class="js-threshold-text"></strong>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">Refund details</div>
                    <div class="card-body">
                        @error('items')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror
                        @error('items.*')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror

                        <div class="mb-3">
                            <label for="reason_code" class="form-label">Reason <span class="text-danger">*</span></label>
                            <select class="form-select @error('reason_code') is-invalid @enderror"
                                    id="reason_code" name="reason_code" required>
                                @foreach ($reasons as $value => $label)
                                    <option value="{{ $value }}" @selected(old('reason_code', 'changed_mind') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('reason_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3 js-reason-detail">
                            <label for="reason" class="form-label">Explain <span class="text-danger">*</span></label>
                            <textarea class="form-control @error('reason') is-invalid @enderror" id="reason" name="reason"
                                      rows="2" maxlength="255"
                                      placeholder="What happened?">{{ old('reason') }}</textarea>
                            <div class="form-text">
                                Required for “Other” so the report says something useful later.
                            </div>
                            @error('reason')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="method" class="form-label">Refund method <span class="text-danger">*</span></label>
                            <select class="form-select @error('method') is-invalid @enderror" id="method" name="method" required>
                                @foreach (\App\Enums\PaymentMethod::cases() as $method)
                                    <option value="{{ $method->value }}" @selected(old('method', \App\Enums\PaymentMethod::Cash->value) === $method->value)>
                                        {{ $method->label() }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">Cash refunds come out of the drawer and show on the reconciliation report.</div>
                            @error('method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="note" class="form-label">Internal note</label>
                            <textarea class="form-control @error('note') is-invalid @enderror" id="note" name="note"
                                      rows="2" maxlength="1000">{{ old('note') }}</textarea>
                            @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="alert alert-light border small mb-3">
                            <i class="bi bi-box-arrow-in-down me-1"></i>
                            Refunded units go back to stock automatically and are logged as a
                            <code>refund</code> movement. The amount is worked out from this
                            order's lines — it cannot be typed in.
                        </div>

                        <button type="submit" class="btn btn-warning w-100" id="refund-submit">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Process Refund
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endif
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    const form = document.getElementById('refund-form');
    if (!form) return;

    const currency = form.dataset.currency;
    const threshold = parseFloat(form.dataset.threshold || '0');
    const inputs = Array.from(form.querySelectorAll('.js-qty'));
    const totalEl = form.querySelector('.js-total');
    const submitBtn = document.getElementById('refund-submit');
    const reasonSelect = document.getElementById('reason_code');
    const reasonDetail = form.querySelector('.js-reason-detail');
    const overThreshold = form.querySelector('.js-over-threshold');
    const thresholdText = form.querySelector('.js-threshold-text');

    const money = (value) => currency + Number(value).toFixed(2);

    function recalculate() {
        let total = 0;

        inputs.forEach((input) => {
            const row = input.closest('tr');
            const lineEl = row.querySelector('.js-line-total');
            // Tax-inclusive unit total, matching what the server will charge.
            const price = parseFloat(input.dataset.price || '0');
            const max = parseInt(input.max, 10) || 0;
            const qty = Math.max(0, Math.min(parseInt(input.value || '0', 10) || 0, max));
            const line = price * qty;

            total += line;
            lineEl.textContent = money(line);
        });

        totalEl.textContent = money(total);

        // Warn before the cashier commits, not after. The refund still goes
        // through - the owner is told either way - but they know it is being
        // flagged, so nobody is surprised by a Telegram message later.
        const high = threshold > 0 && total > threshold;
        if (overThreshold) {
            overThreshold.classList.toggle('d-none', !high);
            if (high) {
                thresholdText.textContent =
                    'This refund is over the ' + money(threshold) +
                    ' review threshold. It will still be processed and the owner will be alerted.';
            }
        }
    }

    inputs.forEach((input) => {
        input.addEventListener('input', () => {
            if (parseInt(input.value, 10) > parseInt(input.max, 10)) {
                input.value = input.max;
            }
            recalculate();
        });
    });

    form.querySelector('.js-max-all').addEventListener('click', () => {
        inputs.forEach((input) => { input.value = input.max; });
        recalculate();
    });

    form.querySelector('.js-clear-all').addEventListener('click', () => {
        inputs.forEach((input) => { input.value = 0; });
        recalculate();
    });

    function syncReasonDetail() {
        if (!reasonSelect || !reasonDetail) return;
        const needsDetail = reasonSelect.value === 'other';
        reasonDetail.classList.toggle('d-none', !needsDetail);
        reasonDetail.querySelector('#reason').required = needsDetail;
    }

    reasonSelect?.addEventListener('change', syncReasonDetail);
    syncReasonDetail();

    form.addEventListener('submit', (event) => {
        const hasItems = inputs.some((input) => parseInt(input.value || '0', 10) > 0);

        if (!hasItems) {
            event.preventDefault();
            alert('Select at least one item to refund.');
            return;
        }

        // The server deduplicates on the idempotency key as well - this is only
        // here so the cashier sees the button react instead of wondering whether
        // the first click registered.
        submitBtn.disabled = true;
        submitBtn.innerHTML =
            '<span class="spinner-border spinner-border-sm me-1"></span>Processing…';
    });
})();
</script>
@endpush
