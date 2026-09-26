@extends('layouts.app')

@section('title', 'Receive '.$product->name)

@section('content')
    <a href="{{ route('admin.lots.index', $product) }}" class="mb-4 inline-block text-sm text-slate-500 hover:text-slate-800">
        &larr; Back to batches
    </a>

    <h1 class="mb-6 text-xl font-bold text-slate-900">Receive a delivery of {{ $product->name }}</h1>

    <form method="POST" action="{{ route('admin.lots.store', $product) }}"
          class="max-w-2xl rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        @csrf

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-text-input name="code" :value="old('code')" label="Batch reference" required
                              placeholder="e.g. DLV-4471 or 2026-09-26" />
                <p class="mt-1 text-xs text-slate-500">
                    Receiving into an existing reference adds to it, so the same delivery twice
                    shows as a top-up rather than a duplicate batch.
                </p>
            </div>

            <div>
                <x-text-input name="quantity" type="number" min="1" :value="old('quantity')" label="Quantity" required />
            </div>

            <div>
                <x-text-input name="expires_at" type="date" :value="old('expires_at')" label="Expires on"
                              hint="Leave blank for goods that do not expire." />
            </div>

            <div>
                <x-text-input name="cost" type="number" step="0.01" min="0" :value="old('cost')" label="Unit cost"
                              hint="What this batch was bought at, if different from the product cost." />
            </div>

            <div>
                <x-textarea-input name="notes" :value="old('notes')" label="Notes" />
            </div>
        </div>

        <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-5">
            <a href="{{ route('admin.lots.index', $product) }}"
               class="rounded-lg border border-slate-300 px-5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Cancel
            </a>
            <button type="submit" class="rounded-lg bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
                Receive into batch
            </button>
        </div>
    </form>
@endsection
