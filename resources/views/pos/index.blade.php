@extends('layouts.app')

@section('title', 'Point of Sale')
@section('page-title', 'Point of Sale')
@section('page-subtitle', 'Ring up a sale')

@section('content')
<div class="row g-3">
    {{-- ================= Product picker ================= --}}
    <div class="col-lg-7 col-xl-8">
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('pos.index') }}" class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label for="q" class="form-label small fw-semibold">Search</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="q" name="q"
                                   value="{{ request('q') }}" placeholder="Product name…">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label for="category_id" class="form-label small fw-semibold">Category</label>
                        <select class="form-select" id="category_id" name="category_id">
                            <option value="">All categories</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
                    </div>
                </form>
            </div>
        </div>

        @if ($products->isEmpty())
            <div class="card">
                <div class="card-body text-center py-5 text-body-secondary">
                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                    No products match your search.
                </div>
            </div>
        @else
            <div class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-3 pos-grid">
                @foreach ($products as $product)
                    @php
                        $sellable = $product->sellableStock();
                        $expired = $product->expiredQuantity();
                        $expiryStatus = $product->expiryStatus();
                        $soonest = $product->batches
                            ->filter(fn ($b) => $b->hasExpiryDate() && (int) $b->quantity > 0)
                            ->sortBy(fn ($b) => $b->expiry_date->timestamp)
                            ->first();
                        $blocked = $sellable <= 0;
                    @endphp
                    <div class="col">
                        {{-- A product whose every lot has lapsed cannot be sold,
                             even though stock is still counted. --}}
                        <div class="card pos-product-card {{ $blocked ? 'disabled' : '' }}"
                             data-product-id="{{ $product->id }}"
                             data-stock="{{ $sellable }}"
                             role="button" tabindex="0"
                             aria-disabled="{{ $blocked ? 'true' : 'false' }}">
                            <div class="card-body p-2 text-center">
                                @if ($product->hasImage())
                                    <img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}" loading="lazy"
                                         class="rounded-2 mb-2" style="width:44px;height:44px;object-fit:cover;">
                                @else
                                    <span class="product-thumb mb-2" style="width:44px;height:44px;">
                                        {{ strtoupper(mb_substr($product->name, 0, 2)) }}
                                    </span>
                                @endif
                                <div class="small fw-semibold text-truncate" title="{{ $product->name }}">{{ $product->name }}</div>
                                <div class="fw-bold mt-1 money">{{ \App\Models\Setting::money($product->selling_price) }}</div>

                                @if ($blocked)
                                    <span class="badge text-bg-danger mt-1">Expired</span>
                                @elseif ($expiryStatus === 'expiring')
                                    <span class="badge text-bg-warning mt-1">
                                        {{ $soonest?->expiry_date->format('M j') }}
                                    </span>
                                    <div class="small text-warning-emphasis">Expiring soon</div>
                                @elseif ($expired > 0)
                                    <span class="badge text-bg-warning mt-1">{{ $sellable }} sellable</span>
                                @elseif ($sellable <= $product->low_stock_threshold)
                                    <span class="badge text-bg-warning mt-1">{{ $sellable }} left</span>
                                @else
                                    <span class="badge text-bg-light text-body-secondary mt-1">{{ $sellable }} in stock</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ================= Cart ================= --}}
    <div class="col-lg-5 col-xl-4">
        <div class="card pos-cart">
            <div class="card-header d-flex align-items-center">
                <i class="bi bi-cart3 me-2"></i>Current Sale
                <span class="badge text-bg-secondary ms-2" id="cart-count">{{ $totals['item_count'] }} item(s)</span>
                <button type="button" class="btn btn-sm btn-link text-danger ms-auto p-0 text-decoration-none"
                        id="clear-cart">Clear</button>
            </div>

            <div class="card-body pos-cart-body" id="cart-items">
                @include('pos.partials.cart-items')
            </div>

            <div class="card-footer bg-body-tertiary">
                @include('pos.partials.cart-totals')
            </div>
        </div>
    </div>
</div>

{{-- ================= Checkout modal ================= --}}
<div class="modal fade" id="checkoutModal" tabindex="-1" aria-labelledby="checkoutModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('pos.checkout') }}" id="checkout-form">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title" id="checkoutModalLabel">Take Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="d-flex justify-content-between align-items-center border rounded p-3 mb-3 bg-body-tertiary">
                    <span class="text-body-secondary">Amount Due</span>
                    <span class="fs-3 fw-bold money" id="due-amount">{{ \App\Models\Setting::money($totals['total']) }}</span>
                </div>

                <div class="mb-3">
                    <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                    <select class="form-select @error('payment_method') is-invalid @enderror" id="payment_method" name="payment_method" required>
                        @foreach (\App\Enums\PaymentMethod::options() as $value => $label)
                            <option value="{{ $value }}" @selected(old('payment_method', 'cash') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('payment_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="paid_amount" class="form-label">Amount Received <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" min="0"
                           class="form-control form-control-lg @error('paid_amount') is-invalid @enderror"
                           id="paid_amount" name="paid_amount" required
                           value="{{ old('paid_amount', number_format($totals['total'], 2, '.', '')) }}">
                    @error('paid_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="d-flex justify-content-between align-items-center border rounded p-3 mb-3 bg-body-tertiary">
                    <span class="text-body-secondary">Change Due</span>
                    <span class="fs-5 fw-bold money text-success" id="change-amount">
                        {{ \App\Models\Setting::money(0) }}
                    </span>
                </div>

                <div class="mb-3">
                    <label for="note" class="form-label">Note <span class="text-body-secondary small">(optional)</span></label>
                    <input type="text" class="form-control" id="note" name="note"
                           maxlength="255" value="{{ old('note') }}" placeholder="e.g. take-away, special request">
                    @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success btn-lg" id="confirm-sale">
                    <i class="bi bi-check2-circle me-1"></i>Complete Sale
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const currency = @json(\App\Models\Setting::currency());

    const money = (value) => currency + Number(value || 0).toFixed(2);

    const cartItemsEl = document.getElementById('cart-items');
    const cartCountEl = document.getElementById('cart-count');
    const cartQuantityEl = document.getElementById('cart-quantity');
    const cartSubtotalEl = document.getElementById('cart-subtotal');
    const cartTaxEl = document.getElementById('cart-tax');
    const cartTotalEl = document.getElementById('cart-total');
    const dueAmountEl = document.getElementById('due-amount');
    const changeAmountEl = document.getElementById('change-amount');
    const paidInput = document.getElementById('paid_amount');
    const checkoutForm = document.getElementById('checkout-form');
    const confirmBtn = document.getElementById('confirm-sale');

    const checkoutModalEl = document.getElementById('checkoutModal');
    const checkoutModal = new bootstrap.Modal(checkoutModalEl);

    let totals = @json($totals);

    /** POST/PATCH/DELETE helper that surfaces server-side messages. */
    async function request(url, method, body = {}) {
        const options = {
            method: method,
            headers: {
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        };

        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }

        const response = await fetch(url, options);

        if (response.status === 419) {
            window.location.reload();
            return null;
        }

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            alert(data.message || data.errors?.paid_amount?.[0] || 'Something went wrong. Please try again.');
            return null;
        }

        return data;
    }

    function applyTotals(next) {
        totals = next;

        cartCountEl.textContent = `${next.item_count} item(s)`;

        if (cartQuantityEl) cartQuantityEl.textContent = next.quantity;
        if (cartSubtotalEl) cartSubtotalEl.textContent = money(next.subtotal);
        if (cartTaxEl) cartTaxEl.textContent = money(next.tax_amount);
        if (cartTotalEl) cartTotalEl.textContent = money(next.total);

        dueAmountEl.textContent = money(next.total);

        // Keep the payable amount in step with the cart unless edited by hand.
        if (paidInput && paidInput.dataset.touched !== '1') {
            paidInput.value = Number(next.total).toFixed(2);
        }

        updateChange();
    }

    function updateChange() {
        if (!paidInput || !changeAmountEl) return;
        const change = (parseFloat(paidInput.value || '0') - Number(totals.total || 0));
        changeAmountEl.textContent = money(change > 0 ? change : 0);
        changeAmountEl.classList.toggle('text-success', change > 0);
        changeAmountEl.classList.toggle('text-danger', change < 0);
    }

    function renderCart(html) {
        cartItemsEl.innerHTML = html;
        bindCartControls();
    }

    function bindCartControls() {
        cartItemsEl.querySelectorAll('[data-cart-qty]').forEach((input) => {
            input.addEventListener('change', async () => {
                const productId = input.dataset.cartQty;
                const data = await request(`/pos/cart/${productId}`, 'PATCH', {
                    quantity: parseInt(input.value || '0', 10),
                });

                if (data) {
                    renderCart(data.items_html);
                    applyTotals(data.totals);
                }
            });
        });

        cartItemsEl.querySelectorAll('[data-cart-remove]').forEach((button) => {
            button.addEventListener('click', async () => {
                const data = await request(`/pos/cart/${button.dataset.cartRemove}`, 'DELETE');
                if (data) {
                    renderCart(data.items_html);
                    applyTotals(data.totals);
                }
            });
        });
    }

    // --- Product grid: click to add -------------------------------------
    document.querySelectorAll('.pos-product-card').forEach((card) => {
        const add = async () => {
            if (card.getAttribute('aria-disabled') === 'true') {
                alert('This product is out of stock.');
                return;
            }

            const data = await request('/pos/cart', 'POST', { product_id: parseInt(card.dataset.productId, 10) });

            if (data) {
                renderCart(data.items_html);
                applyTotals(data.totals);
            }
        };

        card.addEventListener('click', add);
        card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                add();
            }
        });
    });

    // --- Clear cart ------------------------------------------------------
    document.getElementById('clear-cart')?.addEventListener('click', async () => {
        if (!confirm('Remove all items from the current sale?')) return;

        const data = await request('/pos/cart', 'DELETE');
        if (data) {
            renderCart(data.items_html);
            applyTotals(data.totals);
        }
    });

    // --- Payment ---------------------------------------------------------
    paidInput?.addEventListener('input', () => {
        paidInput.dataset.touched = '1';
        updateChange();
    });

    document.getElementById('open-checkout')?.addEventListener('click', () => {
        if (totals.item_count === 0) {
            alert('Add at least one product before taking payment.');
            return;
        }
        checkoutModal.show();
    });

    checkoutForm?.addEventListener('submit', () => {
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing…';
    });

    // Enable the checkout button only when the cart has something in it.
    function refreshCheckoutButton() {
        if (!confirmBtn) return;
        confirmBtn.disabled = totals.item_count === 0;
    }

    refreshCheckoutButton();
    const observer = new MutationObserver(refreshCheckoutButton);
    observer.observe(cartCountEl, { childList: true, subtree: true, characterData: true });

    bindCartControls();
})();
</script>
@endpush
