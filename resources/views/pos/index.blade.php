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
                    <div class="col-md-5">
                        <label for="q" class="form-label small fw-semibold">Search</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="q" name="q"
                                   value="{{ request('q') }}" placeholder="Product name or barcode…">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label for="category_id" class="form-label small fw-semibold">Category</label>
                        <select class="form-select" id="category_id" name="category_id">
                            <option value="">All categories</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-warning w-100" id="open-scanner">
                            <i class="bi bi-upc-scan"></i> Scan
                        </button>
                    </div>
                </form>

                <div class="form-text mt-2">
                    <i class="bi bi-info-circle me-1"></i>
                    Scanning is a shortcut, not the only way in - the search box and the
                    product grid below always work, so a barcode that will not read
                    costs nothing but a few keystrokes.
                </div>
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
                                        {{ \App\Support\DateFormat::dayShort($soonest?->expiry_date) }}
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

                {{-- GCash reference. The customer shows the code on their phone
                     and the cashier reads it off, so the payment can be matched
                     to the remittance later. Cash sales have no reference. --}}
                <div class="mb-3 d-none" id="gcash-reference-wrap">
                    <label for="gcash_reference" class="form-label">GCash Reference No.</label>
                    <input type="text" class="form-control @error('gcash_reference') is-invalid @enderror"
                           id="gcash_reference" name="gcash_reference" maxlength="60"
                           value="{{ old('gcash_reference') }}" placeholder="From the customer's GCash app">
                    @error('gcash_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Read this off the customer's phone so the payment can be matched to the remittance.</div>
                </div>

                {{-- Senior citizen / PWD. A statutory discount, not a promo: the
                     rate is a store setting, the cashier cannot change it, and
                     the customer's name and ID are recorded so the claim is
                     checkable afterwards. --}}
                @if (\App\Models\Setting::scpwdDiscountRate() > 0)
                    <div class="border rounded p-3 mb-3 bg-body-tertiary">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1"
                                   id="scpwd" name="scpwd" @checked(old('scpwd'))
                                   aria-describedby="scpwd-help">
                            <label class="form-check-label fw-semibold" for="scpwd">
                                <i class="bi bi-person-check me-1"></i>
                                Senior Citizen / PWD
                                <span class="badge text-bg-primary ms-1">{{ rtrim(rtrim(\App\Models\Setting::scpwdDiscountRate(), '0'), '.') }}% off</span>
                            </label>
                        </div>
                        <div class="form-text" id="scpwd-help">
                            A legal entitlement, not a promotion. Take the ID first.
                        </div>

                        <div class="row g-2 mt-1 d-none" id="scpwd-fields">
                            <div class="col-12">
                                <input type="text" class="form-control @error('scpwd_name') is-invalid @enderror"
                                       id="scpwd_name" name="scpwd_name" maxlength="150"
                                       value="{{ old('scpwd_name') }}" placeholder="Customer's full name">
                                @error('scpwd_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-6">
                                <select class="form-select @error('scpwd_id_type') is-invalid @enderror"
                                        id="scpwd_id_type" name="scpwd_id_type">
                                    <option value="">ID type…</option>
                                    @foreach (\App\Models\Setting::scpwdIdTypes() as $type)
                                        <option value="{{ $type }}" @selected(old('scpwd_id_type') === $type)>{{ $type }}</option>
                                    @endforeach
                                </select>
                                @error('scpwd_id_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-6">
                                <input type="text" class="form-control @error('scpwd_id_number') is-invalid @enderror"
                                       id="scpwd_id_number" name="scpwd_id_number" maxlength="60"
                                       value="{{ old('scpwd_id_number') }}" placeholder="ID number">
                                @error('scpwd_id_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="d-none mt-2" id="scpwd-summary"></div>
                    </div>
                @endif

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

                {{-- Tells the cashier which notes to hand over, which is the part
                     they actually get wrong. Greedy over the denominations a
                     Philippine till is stocked with. --}}
                <div class="d-none mb-3" id="change-breakdown">
                    <div class="small fw-semibold text-body-secondary mb-1">
                        <i class="bi bi-cash-coin me-1"></i>Make change with
                    </div>
                    <div class="d-flex flex-wrap gap-1" id="change-breakdown-notes"></div>
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

{{-- ================= Barcode scanner modal =================
     Deliberately additive. Nothing about selling depends on this dialog: the
     search box, the product grid and the checkout modal all work with the
     scanner never opened, and a camera that fails to start leaves the till
     exactly as it was. --}}
<div class="modal fade" id="scannerModal" tabindex="-1" aria-labelledby="scannerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="scannerModalLabel"><i class="bi bi-upc-scan me-1"></i>Scan a barcode</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div id="scanner-viewport" style="min-height:240px;"></div>

                <div id="scanner-status" class="alert alert-info d-flex align-items-center mt-3 mb-0" role="status" aria-live="polite">
                    <span id="scanner-status-text">Point the camera at a barcode.</span>
                </div>

                <hr>

                {{-- Typing or pasting the number is the manual path, and is also
                     what a USB/Bluetooth scanner in keyboard-wedge mode drives:
                     it types the digits and presses Enter. --}}
                <form id="scanner-manual-form" class="input-group">
                    <span class="input-group-text"><i class="bi bi-keyboard"></i></span>
                    <input type="text" class="form-control" id="scanner-manual-input"
                           inputmode="numeric" pattern="[0-9]*" autocomplete="off"
                           placeholder="…or type the barcode and press Enter">
                    <button class="btn btn-primary" type="submit">Add</button>
                </form>
                <div class="form-text">
                    A USB scanner works too - it needs no setup, just this box focused.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
{{-- Vendored, not a CDN: a store that loses its internet must still be able
     to sell, and the scanner is the one part of the till that needs a
     third-party file. If this fails to load the scanner degrades to its typed
     fallback rather than breaking the page. --}}
<script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}"></script>
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

    const paymentMethodEl = document.getElementById('payment_method');
    const gcashWrap = document.getElementById('gcash-reference-wrap');
    const scpwdCheckbox = document.getElementById('scpwd');
    const scpwdFields = document.getElementById('scpwd-fields');
    const scpwdSummary = document.getElementById('scpwd-summary');
    const changeWrap = document.getElementById('change-breakdown');
    const changeNotes = document.getElementById('change-breakdown-notes');
    const scpwdRate = @json(\App\Models\Setting::scpwdDiscountRate());

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
        const change = (parseFloat(paidInput.value || '0') - amountDue());
        changeAmountEl.textContent = money(change > 0 ? change : 0);
        changeAmountEl.classList.toggle('text-success', change > 0);
        changeAmountEl.classList.toggle('text-danger', change < 0);
        renderChangeBreakdown(change);
    }

    /**
     * What the customer owes right now: the cart total, less a senior citizen /
     * PWD discount if the cashier has ticked the box.
     */
    function amountDue() {
        if (!scpwdCheckbox || !scpwdCheckbox.checked) return Number(totals.total || 0);
        return Math.max(0, Number(totals.total || 0) * (1 - scpwdRate / 100));
    }

    // Greedy over the notes and coins a provincial till is actually stocked
    // with. A cashier making change from a 100-peso note and coins is the
    // common case, so the list starts there and counts down.
    const DENOMINATIONS = [100, 50, 20, 10, 5, 1];

    function renderChangeBreakdown(change) {
        if (!changeWrap || !changeNotes) return;

        if (change <= 0) {
            changeWrap.classList.add('d-none');
            return;
        }

        // Work in whole centavos so a peso-eighty change does not drift.
        let remaining = Math.round(change * 100);
        const parts = [];

        for (const denomination of DENOMINATIONS) {
            const unit = denomination * 100;
            const count = Math.floor(remaining / unit);

            if (count > 0) {
                remaining -= count * unit;
                parts.push({ denomination, count });
            }
        }

        changeWrap.classList.remove('d-none');
        changeNotes.innerHTML = '';

        for (const part of parts) {
            const chip = document.createElement('span');
            chip.className = 'badge text-bg-light border text-body';
            chip.textContent = `${part.count}× ${money(part.denomination)}`;
            changeNotes.appendChild(chip);
        }
    }

    // --- Senior citizen / PWD ---------------------------------------------
    // Purely a client-side preview. The real amount is computed on the server
    // from the store setting when the order is written, so ticking this box
    // changes what the cashier is shown, not what the customer is charged.
    function syncScpwd() {
        if (!scpwdCheckbox || !scpwdFields) return;

        const on = scpwdCheckbox.checked;
        scpwdFields.classList.toggle('d-none', !on);

        if (scpwdSummary) {
            if (on && scpwdRate > 0) {
                const saved = Number(totals.total || 0) * (scpwdRate / 100);
                scpwdSummary.classList.remove('d-none');
                scpwdSummary.innerHTML =
                    `<div class="d-flex justify-content-between small">` +
                    `<span class="text-body-secondary">Discount</span>` +
                    `<span class="text-success">− ${money(saved)}</span></div>`;
            } else {
                scpwdSummary.classList.add('d-none');
                scpwdSummary.innerHTML = '';
            }
        }

        if (dueAmountEl) dueAmountEl.textContent = money(amountDue());

        // Clear the flag so an un-tick restores the full amount.
        if (paidInput && paidInput.dataset.touched !== '1') {
            paidInput.value = amountDue().toFixed(2);
        }

        updateChange();
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

    // --- Barcode scanner -------------------------------------------------
    // Additive by construction: if the vendored library is missing, the camera
    // is absent, or permission is refused, the manual input below still works
    // and the product grid and search box were never touched.
    const scannerModalEl = document.getElementById('scannerModal');
    const scannerViewportEl = document.getElementById('scanner-viewport');
    const scannerStatusEl = document.getElementById('scanner-status');
    const scannerStatusTextEl = document.getElementById('scanner-status-text');
    const scannerManualForm = document.getElementById('scanner-manual-form');
    const scannerManualInput = document.getElementById('scanner-manual-input');

    let scanner = null;
    let scannerRunning = false;
    let lastScanned = '';
    let lastScannedAt = 0;

    const LOOKUP_URL = @json(route('pos.lookup'));
    const CART_URL = @json(route('pos.cart.store'));
    const RETRY_WINDOW_MS = 1500;

    function setScannerStatus(kind, text) {
        scannerStatusEl.className = `alert alert-${kind} d-flex align-items-center mt-3 mb-0`;
        scannerStatusTextEl.textContent = text;
    }

    async function startScanner() {
        if (scannerRunning) return;

        if (typeof Html5Qrcode === 'undefined') {
            setScannerStatus('warning', 'The scanner library did not load. Type the barcode below instead.');
            return;
        }

        scanner = new Html5Qrcode('scanner-viewport');
        scannerRunning = true;

        try {
            await scanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 280, height: 140 }, aspectRatio: 1.6 },
                (decodedText) => handleBarcode(decodedText),
                // Fires continuously while the frame holds no readable code.
                // It is not an error worth surfacing.
                () => {}
            );
        } catch (e) {
            scannerRunning = false;
            const denied = e && (e.name === 'NotAllowedError' || /permission/i.test(e.message || ''));
            setScannerStatus(
                'warning',
                denied
                    ? 'Camera permission was refused. Allow it in the browser, or type the barcode below.'
                    : 'No camera is available. Type the barcode below - scanning is optional.'
            );
        }
    }

    async function stopScanner() {
        if (!scanner || !scannerRunning) {
            scannerRunning = false;
            return;
        }

        scannerRunning = false;

        try {
            await scanner.stop();
            scanner.clear();
        } catch (e) {
            // A scanner that is already down is not a problem worth reporting.
        }
    }

    /**
     * Resolve a decoded number to a product and add it to the cart.
     *
     * A successful scan leaves the camera running so the next item can be
     * rung up without reopening anything - a barcode takes about a second, and
     * closing the dialog between every item would undo the point of scanning.
     */
    async function handleBarcode(rawCode) {
        const code = String(rawCode || '').trim();

        if (!/^[0-9]{6,32}$/.test(code)) return;

        // The camera reports the same code many times a second while it stays
        // in frame. Without this the cart gains thirty of one item.
        const now = Date.now();
        if (code === lastScanned && now - lastScannedAt < RETRY_WINDOW_MS) return;
        lastScanned = code;
        lastScannedAt = now;

        setScannerStatus('info', `Looking up ${code}…`);

        let found = false;
        let product = null;
        let message = '';

        try {
            const response = await fetch(`${LOOKUP_URL}?barcode=${encodeURIComponent(code)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            });
            const data = await response.json().catch(() => ({}));

            if (response.ok && data.found) {
                found = true;
                product = data.product;
            } else {
                message = data.message || `No product is filed under barcode ${code}.`;
            }
        } catch (e) {
            message = 'Could not reach the server. Check the connection, or search by name.';
        }

        if (!found) {
            setScannerStatus('warning', message);
            return;
        }

        // Route the product through the ordinary cart endpoint so a scanned item
        // is checked for stock, expiry and active status exactly like a click.
        const data = await request(CART_URL, 'POST', { product_id: product.id, quantity: 1 });

        if (!data) {
            setScannerStatus('danger', 'That product could not be added to the sale.');
            return;
        }

        renderCart(data.items_html);
        applyTotals(data.totals);
        setScannerStatus('success', `${product.name} added to the sale.`);
    }

    document.getElementById('open-scanner')?.addEventListener('click', () => {
        scannerModal.show();
    });

    scannerModalEl?.addEventListener('shown.bs.modal', () => {
        lastScanned = '';
        lastScannedAt = 0;
        setScannerStatus('info', 'Point the camera at a barcode.');
        startScanner();
    });

    // Free the camera the moment the dialog closes, or the browser keeps the
    // webcam light on for the rest of the shift.
    scannerModalEl?.addEventListener('hidden.bs.modal', () => {
        stopScanner();
        if (scannerViewportEl) scannerViewportEl.innerHTML = '';
    });

    scannerManualForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const value = scannerManualInput.value.trim();

        if (!value) return;

        await handleBarcode(value);
        scannerManualInput.value = '';
        scannerManualInput.focus();
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

    // The GCash box is noise on a cash sale, so it only appears when GCash is
    // actually selected.
    function syncPaymentMethod() {
        if (!paymentMethodEl || !gcashWrap) return;

        const isGcash = paymentMethodEl.value === 'gcash';
        gcashWrap.classList.toggle('d-none', !isGcash);

        if (!isGcash) {
            const ref = document.getElementById('gcash_reference');
            if (ref) ref.value = '';
        }
    }

    paymentMethodEl?.addEventListener('change', syncPaymentMethod);
    scpwdCheckbox?.addEventListener('change', syncScpwd);
    syncPaymentMethod();
    syncScpwd();

    // Opening the dialog for a new sale should not carry the previous sale's
    // discount claim over to this one.
    checkoutModalEl?.addEventListener('shown.bs.modal', () => {
        if (paidInput) {
            paidInput.dataset.touched = '';
            paidInput.value = amountDue().toFixed(2);
        }
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
