@if ($cartItems->isEmpty())
    <div class="text-center text-body-secondary py-4">
        <i class="bi bi-basket fs-2 d-block mb-2"></i>
        <div class="small">Your cart is empty.</div>
        <div class="small">Tap a product to add it to the sale.</div>
    </div>
@else
    @foreach ($cartItems as $line)
        <div class="pos-cart-item">
            <div class="d-flex align-items-start gap-2">
                <div class="min-w-0 flex-grow-1">
                    <div class="small fw-semibold text-truncate" title="{{ $line['name'] }}">{{ $line['name'] }}</div>
                    <div class="text-body-secondary" style="font-size:0.72rem;"> {{ \App\Models\Setting::money($line['unit_price']) }} / {{ $line['unit'] }}
                    </div>
                </div>
                <div class="money small fw-semibold">{{ \App\Models\Setting::money($line['line_total']) }}</div>
            </div>

            <div class="d-flex align-items-center gap-2 mt-1">
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                        data-cart-qty="{{ $line['product_id'] }}" data-step="-1"
                        onclick="this.nextElementSibling.stepDown(); this.nextElementSibling.dispatchEvent(new Event('change'))"
                        aria-label="Decrease quantity">&minus;</button>

                <input type="number" min="0" step="1" value="{{ $line['quantity'] }}"
                       class="form-control form-control-sm qty-input py-0"
                       data-cart-qty="{{ $line['product_id'] }}"
                       aria-label="Quantity for {{ $line['name'] }}">

                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                        onclick="this.previousElementSibling.stepUp(); this.previousElementSibling.dispatchEvent(new Event('change'))"
                        aria-label="Increase quantity">+</button>

                <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-auto text-decoration-none"
                        data-cart-remove="{{ $line['product_id'] }}" title="Remove item">
                    <i class="bi bi-trash"></i>
                </button>
            </div>

            @if (! $line['stock_ok'])
                <div class="text-danger" style="font-size:0.72rem;">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    @if ($line['stock'] === null)
                        No longer available
                    @elseif ($line['is_fully_expired'] ?? false)
                        All stock past its expiry date
                    @else
                        Only {{ $line['sellable_stock'] }} of {{ $line['stock'] }} can be sold &mdash; the rest is expired
                    @endif
                </div>
            @elseif ($line['is_expired'] ?? false)
                <div class="text-danger" style="font-size:0.72rem;">
                    <i class="bi bi-exclamation-octagon me-1"></i>
                    Some of this stock is past its expiry date
                </div>
            @elseif (! empty($line['is_expiring']) && ! empty($line['expiry_label']))
                <div class="text-warning-emphasis" style="font-size:0.72rem;">
                    <i class="bi bi-clock me-1"></i>{{ $line['expiry_label'] }}
                </div>
            @endif
        </div>
    @endforeach
@endif
