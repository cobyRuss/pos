@extends('layouts.app')

@section('title', 'Cash Drawer')
@section('page-title', 'Cash Drawer')
@section('page-subtitle', 'Count the drawer at the end of a shift and see what it should hold')

@section('content')

@if ($current)
    {{-- ================= Open drawer ================= --}}
    <div class="card mb-3 border-primary">
        <div class="card-header d-flex align-items-center">
            <i class="bi bi-cash-stack me-2"></i>Your drawer is open
            <span class="badge text-bg-success ms-2">Opened {{ \App\Support\DateFormat::time($current->opened_at) }}</span>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3">
                    <div class="small text-body-secondary">Opening float</div>
                    <div class="h5 money mb-0">{{ \App\Models\Setting::money($current->opening_float) }}</div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="small text-body-secondary">Cash sales since</div>
                    <div class="h5 money mb-0">{{ \App\Models\Setting::money($summary['cash_sales']) }}</div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="small text-body-secondary">Cash refunded out</div>
                    <div class="h5 money mb-0">− {{ \App\Models\Setting::money($summary['cash_refunds']) }}</div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="small text-body-secondary">Should be in the drawer</div>
                    <div class="h5 money mb-0 text-primary">{{ \App\Models\Setting::money($summary['expected_cash']) }}</div>
                </div>
            </div>

            <hr>

            @if (auth()->user()->isStaff())
                <form method="POST" action="{{ route('pos.drawer.close', $current) }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-md-4">
                        <label for="counted_cash" class="form-label">Counted cash <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" required
                               class="form-control form-control-lg @error('counted_cash') is-invalid @enderror"
                               id="counted_cash" name="counted_cash"
                               value="{{ old('counted_cash', number_format($summary['expected_cash'], 2, '.', '')) }}">
                        @error('counted_cash')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Count the notes and coins, not the screen.</div>
                    </div>
                    <div class="col-md-4">
                        <label for="variance_reason" class="form-label">If it does not balance, why?</label>
                        <input type="text" class="form-control" id="variance_reason" name="variance_reason"
                               maxlength="255" value="{{ old('variance_reason') }}"
                               placeholder="e.g. gave wrong change, till robbed">
                        <div class="form-text">Optional, but a short drawer with no reason is hard to follow up.</div>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100 btn-lg">
                            <i class="bi bi-check2-circle me-1"></i>Close drawer
                        </button>
                    </div>
                </form>
            @else
                <p class="text-body-secondary mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    This drawer belongs to {{ $current->user?->name }}. Only the cashier holding the
                    cash can count and close it.
                </p>
            @endif
        </div>
    </div>
@elseif (auth()->user()->isStaff())
    {{-- ================= No drawer open ================= --}}
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-cash-stack me-2"></i>Start your shift</div>
        <div class="card-body">
            <p class="text-body-secondary">
                Count the float the owner gave you <em>before</em> you start selling, and enter it here.
                Without a float, the end-of-day count cannot tell a short drawer from a full one.
            </p>

            <form method="POST" action="{{ route('pos.drawer.open') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-4">
                    <label for="opening_float" class="form-label">Opening float <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" min="0" required
                           class="form-control form-control-lg @error('opening_float') is-invalid @enderror"
                           id="opening_float" name="opening_float" value="{{ old('opening_float', '0.00') }}">
                    @error('opening_float')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label for="note" class="form-label">Note <span class="text-body-secondary small">(optional)</span></label>
                    <input type="text" class="form-control" id="note" name="note" maxlength="255"
                           value="{{ old('note') }}" placeholder="e.g. morning shift">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100 btn-lg">
                        <i class="bi bi-unlock me-1"></i>Open drawer
                    </button>
                </div>
            </form>
        </div>
    </div>
@endif

{{-- ================= Counted history ================= --}}
<div class="card">
    <div class="card-header d-flex align-items-center">
        <i class="bi bi-clock-history me-2"></i>Counted drawers
    </div>
    <div class="card-body">
        <form method="GET" action="{{ route('drawer.index') }}" class="row g-2 align-items-end mb-3">
            @if (auth()->user()->isAdmin())
                <div class="col-md-4">
                    <label for="user_id" class="form-label small fw-semibold">Cashier</label>
                    <select class="form-select" id="user_id" name="user_id">
                        <option value="">All cashiers</option>
                        @foreach ($cashiers as $cashier)
                            <option value="{{ $cashier->id }}" @selected(($filters['user_id'] ?? null) == $cashier->id)>
                                {{ $cashier->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-md-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="short_only" name="short_only"
                           @checked($filters['short_only'] ?? false)>
                    <label class="form-check-label" for="short_only">Only drawers that did not balance</label>
                </div>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
            </div>
        </form>

        @if ($sessions->isEmpty())
            <div class="text-center py-4 text-body-secondary">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                No drawer has been counted yet.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Closed</th>
                            <th>Cashier</th>
                            <th class="text-end">Float</th>
                            <th class="text-end">Cash sales</th>
                            <th class="text-end">Refunded</th>
                            <th class="text-end">Expected</th>
                            <th class="text-end">Counted</th>
                            <th class="text-end">Variance</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sessions as $session)
                            @php $variance = $session->variance(); @endphp
                            <tr class="{{ $variance < 0 ? 'table-danger' : ($variance > 0 ? 'table-warning' : '') }}">
                                <td class="text-nowrap">{{ \App\Support\DateFormat::dateTime($session->closed_at) }}</td>
                                <td>{{ $session->user?->name ?? 'Unknown' }}</td>
                                <td class="text-end money">{{ \App\Models\Setting::money($session->opening_float) }}</td>
                                <td class="text-end money">{{ \App\Models\Setting::money($session->cashSales()) }}</td>
                                <td class="text-end money">− {{ \App\Models\Setting::money($session->cashRefunds()) }}</td>
                                <td class="text-end money">{{ \App\Models\Setting::money($session->expectedCash()) }}</td>
                                <td class="text-end money">{{ \App\Models\Setting::money($session->counted_cash) }}</td>
                                <td class="text-end money fw-semibold">
                                    @if ($variance === 0.0)
                                        <span class="text-success">Balanced</span>
                                    @else
                                        <span class="{{ $variance < 0 ? 'text-danger' : 'text-warning' }}">
                                            {{ \App\Models\Setting::money(abs($variance)) }} {{ $variance < 0 ? 'short' : 'over' }}
                                        </span>
                                    @endif
                                </td>
                                <td class="small text-body-secondary">{{ $session->variance_reason ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-3">{{ $sessions->links() }}</div>
        @endif
    </div>
</div>
@endsection
