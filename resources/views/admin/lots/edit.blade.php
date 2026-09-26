@extends('layouts.app')

@section('title', 'Count '.$lot->code)

@section('content')
    <a href="{{ route('admin.lots.index', $product) }}" class="mb-4 inline-block text-sm text-slate-500 hover:text-slate-800">
        &larr; Back to batches
    </a>

    <h1 class="mb-6 text-xl font-bold text-slate-900">Count batch {{ $lot->code }} of {{ $product->name }}</h1>

    <form method="POST" action="{{ route('admin.lots.update', [$product, $lot]) }}"
          class="max-w-2xl rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        @csrf
        @method('PUT')

        <p class="mb-4 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
            The system thinks this batch holds <strong>{{ $lot->quantity }}</strong>. Enter what you
            actually counted and the difference is recorded in the movement log against this batch.
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-text-input name="code" :value="old('code', $lot->code)" label="Batch reference" required />
            </div>
            <div>
                <x-text-input name="quantity" type="number" min="0" :value="old('quantity', $lot->quantity)"
                              label="Counted quantity" required />
            </div>
            <div>
                <x-text-input name="expires_at" type="date" :value="old('expires_at', $lot->expires_at?->toDateString())"
                              label="Expires on" />
            </div>
            <div>
                <x-text-input name="cost" type="number" step="0.01" min="0" :value="old('cost', $lot->cost)"
                              label="Unit cost" />
            </div>
            <div class="sm:col-span-2">
                <x-textarea-input name="notes" :value="old('notes', $lot->notes)" label="Notes" />
            </div>
        </div>

        <div class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-5">
            <a href="{{ route('admin.lots.index', $product) }}"
               class="rounded-lg border border-slate-300 px-5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Cancel
            </a>
            <button type="submit" class="rounded-lg bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
                Save count
            </button>
        </div>
    </form>
@endsection
