<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route($route) }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="from" class="form-label small fw-semibold">From</label>
                <input type="date" class="form-control" id="from" name="from" value="{{ $filters['from'] ?? '' }}">
            </div>
            <div class="col-md-3">
                <label for="to" class="form-label small fw-semibold">To</label>
                <input type="date" class="form-control" id="to" name="to" value="{{ $filters['to'] ?? '' }}">
            </div>
            @isset($statuses)
                <div class="col-md-2">
                    <label for="status" class="form-label small fw-semibold">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">All (excl. cancelled)</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endisset
            @isset($methods)
                <div class="col-md-2">
                    <label for="payment_method" class="form-label small fw-semibold">Payment</label>
                    <select class="form-select" id="payment_method" name="payment_method">
                        <option value="">All methods</option>
                        @foreach ($methods as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['payment_method'] ?? '') === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endisset
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel"></i> Apply</button>
                <a href="{{ route($route) }}" class="btn btn-outline-secondary" title="Reset">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>
