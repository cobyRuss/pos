@extends('layouts.app')

@section('title', 'Reports')
@section('page-title', 'Reports')
@section('page-subtitle', 'Current selection: '.$range)

@section('content')
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center">
                <span class="stat-icon bg-primary bg-opacity-10 text-primary me-2"><i class="bi bi-receipt-cutoff"></i></span>
                <div>
                    <div class="fw-semibold">Sales Report</div>
                    <div class="small text-body-secondary">Orders, discounts, taxes and top products</div>
                </div>
            </div>
            <div class="card-body">
                <ul class="list-unstyled small text-body-secondary mb-3">
                    <li class="mb-1"><i class="bi bi-check2"></i> Order totals and payment mix</li>
                    <li class="mb-1"><i class="bi bi-check2"></i> Daily gross vs refunded</li>
                    <li><i class="bi bi-check2"></i> Best selling products</li>
                </ul>
                <a href="{{ route('admin.reports.sales') }}" class="btn btn-primary w-100">
                    Open Sales Report <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center">
                <span class="stat-icon bg-success bg-opacity-10 text-success me-2"><i class="bi bi-bar-chart-line"></i></span>
                <div>
                    <div class="fw-semibold">Revenue Report</div>
                    <div class="small text-body-secondary">Net revenue, methods and cashier performance</div>
                </div>
            </div>
            <div class="card-body">
                <ul class="list-unstyled small text-body-secondary mb-3">
                    <li class="mb-1"><i class="bi bi-check2"></i> Subtotal, tax, discounts, refunds</li>
                    <li class="mb-1"><i class="bi bi-check2"></i> Period-over-period comparison</li>
                    <li><i class="bi bi-check2"></i> Revenue per cashier</li>
                </ul>
                <a href="{{ route('admin.reports.revenue') }}" class="btn btn-primary w-100">
                    Open Revenue Report <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center">
                <span class="stat-icon bg-warning bg-opacity-10 text-warning me-2"><i class="bi bi-boxes"></i></span>
                <div>
                    <div class="fw-semibold">Inventory Report</div>
                    <div class="small text-body-secondary">Stock on hand, valuation and alerts</div>
                </div>
            </div>
            <div class="card-body">
                <ul class="list-unstyled small text-body-secondary mb-3">
                    <li class="mb-1"><i class="bi bi-check2"></i> Cost vs retail stock value</li>
                    <li class="mb-1"><i class="bi bi-check2"></i> Low and out of stock products</li>
                    <li><i class="bi bi-check2"></i> Units on hand per category</li>
                </ul>
                <a href="{{ route('admin.reports.inventory') }}" class="btn btn-primary w-100">
                    Open Inventory Report <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-0">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center">
                <span class="stat-icon bg-danger bg-opacity-10 text-danger me-2"><i class="bi bi-shield-exclamation"></i></span>
                <div>
                    <div class="fw-semibold">Cash Reconciliation</div>
                    <div class="small text-body-secondary">Refunds and expected drawer, per cashier</div>
                </div>
            </div>
            <div class="card-body">
                <ul class="list-unstyled small text-body-secondary mb-3">
                    <li class="mb-1"><i class="bi bi-check2"></i> Cash taken vs cash paid back out</li>
                    <li class="mb-1"><i class="bi bi-check2"></i> Refund rate per cashier</li>
                    <li class="mb-1"><i class="bi bi-check2"></i> Refunds above the review threshold</li>
                    <li><i class="bi bi-check2"></i> Refunds split by reason and day</li>
                </ul>
                <a href="{{ route('admin.reports.refunds') }}" class="btn btn-primary w-100">
                    Open Cash Reconciliation <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">All reports export to CSV</div>
    <div class="card-body small text-body-secondary">
        Every report honours the date range and filters you set, and the
        <i class="bi bi-download"></i> button downloads the filtered rows as a CSV file for Excel or accounting software.
        <div class="mt-2 d-flex flex-wrap gap-2">
            <a href="{{ route('admin.reports.sales', ['export' => 1]) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-download me-1"></i>Sales CSV (last 30 days)
            </a>
            <a href="{{ route('admin.reports.revenue', ['export' => 1]) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-download me-1"></i>Revenue CSV (last 30 days)
            </a>
            <a href="{{ route('admin.reports.inventory', ['export' => 1]) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-download me-1"></i>Inventory CSV (all products)
            </a>
            <a href="{{ route('admin.reports.refunds', ['export' => 1]) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-download me-1"></i>Reconciliation CSV (last 30 days)
            </a>
        </div>
    </div>
</div>
@endsection
