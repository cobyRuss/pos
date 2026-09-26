@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <x-page-header title="System Settings" />

    <form method="POST" action="{{ route('admin.settings.update') }}"
          class="max-w-2xl rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        @csrf
        @method('PUT')

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-text-input name="business_name" label="Store name"
                              :value="old('business_name', $settings[\App\Models\Setting::BUSINESS_NAME] ?? config('app.name'))" required />
                <p class="mt-1 text-xs text-slate-500">Printed at the top of every receipt.</p>
            </div>

            <div class="sm:col-span-2">
                <x-textarea-input name="business_address" label="Address"
                                 :value="old('business_address', $settings[\App\Models\Setting::BUSINESS_ADDRESS] ?? '')" />
            </div>

            <div>
                <x-text-input name="business_phone" label="Phone"
                              :value="old('business_phone', $settings[\App\Models\Setting::BUSINESS_PHONE] ?? '')" />
            </div>

            <div>
                <x-text-input name="currency_symbol" label="Currency symbol"
                              :value="old('currency_symbol', $settings[\App\Models\Setting::CURRENCY_SYMBOL] ?? \App\Models\Setting::FALLBACK_CURRENCY)" required />
            </div>

            <div>
                <x-text-input name="tax_rate" type="number" step="0.01" min="0" max="100" label="Tax rate (%)"
                              :value="old('tax_rate', $settings[\App\Models\Setting::TAX_RATE] ?? 0)" required
                              hint="Applied to every new sale after any discount." />
            </div>

            <div class="sm:col-span-2">
                <x-textarea-input name="receipt_footer" label="Receipt footer"
                                 :value="old('receipt_footer', $settings[\App\Models\Setting::RECEIPT_FOOTER] ?? '')"
                                 hint="Printed at the bottom of every receipt. Leave blank for the default thank-you line." />
            </div>
        </div>

        <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-5">
            <button type="submit" class="rounded-lg bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
                Save settings
            </button>
        </div>
    </form>
@endsection
