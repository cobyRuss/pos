<div id="cart-totals-area">
    <dl class="row mb-2 small">
        <dt class="col-7 text-body-secondary fw-normal">Subtotal (<span id="cart-quantity">{{ $totals['quantity'] }}</span> item(s))</dt>
        <dd class="col-5 text-end money" id="cart-subtotal">{{ \App\Models\Setting::money($totals['subtotal']) }}</dd>

        @if ($totals['tax_rate'] > 0)
            <dt class="col-7 text-body-secondary fw-normal">Tax ({{ rtrim(rtrim(number_format($totals['tax_rate'], 2, '.', ''), '0'), '.') }}%)</dt>
            <dd class="col-5 text-end money" id="cart-tax">{{ \App\Models\Setting::money($totals['tax_amount']) }}</dd>
        @endif
    </dl>

    <div class="d-flex justify-content-between align-items-center border-top pt-2 mb-3">
        <span class="fw-semibold">Total</span>
        <span class="fs-5 fw-bold money" id="cart-total">{{ \App\Models\Setting::money($totals['total']) }}</span>
    </div>

    <button type="button" class="btn btn-success btn-lg w-100" id="open-checkout">
        <i class="bi bi-cash-coin me-1"></i>Take Payment
    </button>
</div>
