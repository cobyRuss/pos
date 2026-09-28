@extends('layouts.app')

@section('title', 'Settings')
@section('page-title', 'Settings')
@section('page-subtitle', 'Store profile, receipt and inventory defaults')

@section('content')
@php
    $groupLabels = [
        'general' => ['Store profile', 'bi-shop'],
        'tax' => ['Taxation', 'bi-percent'],
        'receipt' => ['Receipts', 'bi-receipt'],
        'inventory' => ['Inventory defaults', 'bi-boxes'],
        'refunds' => ['Refund guardrails', 'bi-shield-exclamation'],
        'integrations' => ['Integrations', 'bi-bell'],
    ];
@endphp

<form method="POST" action="{{ route('admin.settings.update') }}">
    @csrf
    @method('PUT')

    <div class="row g-3">
        @foreach ($groupLabels as $group => [$label, $icon])
            @php $groupSettings = array_filter($settings, fn ($setting) => $setting['group'] === $group); @endphp
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center gap-2">
                        <i class="bi {{ $icon }}"></i> {{ $label }}
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            @foreach ($groupSettings as $name => $setting)
                                <div class="{{ in_array($name, ['store_address', 'receipt_footer'], true) ? 'col-12' : 'col-md-6 col-lg-4' }}">
                                    <label for="{{ $name }}" class="form-label">{{ $setting['label'] }}</label>

                                    @if ($name === 'receipt_size')
                                        <select class="form-select @error('receipt_size') is-invalid @enderror" id="{{ $name }}" name="{{ $name }}">
                                            @foreach (['80mm' => '80mm (standard)', '58mm' => '58mm (compact)'] as $value => $text)
                                                <option value="{{ $value }}" @selected(old($name, $setting['value']) === $value)>{{ $text }}</option>
                                            @endforeach
                                        </select>
                                    @elseif (in_array($name, ['store_address', 'receipt_footer'], true))
                                        <textarea class="form-control @error($name) is-invalid @enderror" id="{{ $name }}"
                                                  name="{{ $name }}" rows="2">{{ old($name, $setting['value']) }}</textarea>
                                    @elseif ($name === 'store_email')
                                        <input type="email" class="form-control @error($name) is-invalid @enderror" id="{{ $name }}"
                                               name="{{ $name }}" value="{{ old($name, $setting['value']) }}">
                                    @elseif (in_array($name, ['tax_rate', 'low_stock_default', 'refund_review_threshold', 'refund_daily_limit'], true))
                                        <input type="number" step="0.01" min="0"
                                               class="form-control @error($name) is-invalid @enderror" id="{{ $name }}"
                                               name="{{ $name }}" value="{{ old($name, $setting['value']) }}">
                                    @elseif ($name === 'telegram_bot_token')
                                        <input type="password" class="form-control @error($name) is-invalid @enderror" id="{{ $name }}"
                                               name="{{ $name }}" value="{{ old($name, $setting['value']) }}"
                                               autocomplete="off" placeholder="123456789:AAExxxxxxxxxxxxxxxxx">
                                        <div class="form-text">From @botfather. Not shown on screen once saved.</div>
                                    @else
                                        <input type="text" class="form-control @error($name) is-invalid @enderror" id="{{ $name }}"
                                               name="{{ $name }}" value="{{ old($name, $setting['value']) }}">
                                    @endif

                                    @error($name)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endforeach

        <div class="col-12">
            <div class="card">
                <div class="card-body d-flex flex-wrap align-items-center gap-3">
                    <div class="me-auto">
                        <div class="fw-semibold">Save settings</div>
                        <div class="small text-body-secondary">
                            Changes apply immediately across the till, receipts and reports. Every change is recorded in the audit log.
                        </div>
                    </div>
                    <a href="{{ route('admin.audit-logs.index', ['action' => 'settings_updated']) }}"
                       class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-shield-check me-1"></i>View change history
                    </a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Settings</button>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
