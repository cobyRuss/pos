@extends('layouts.app')

@section('title', 'Adjust stock')

@section('content')
    <a href="{{ route('inventory.index') }}" class="mb-4 inline-block text-sm text-slate-500 hover:text-slate-800">
        &larr; Back to inventory
    </a>

    <div class="mx-auto max-w-2xl space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-6">
            <h1 class="text-lg font-bold text-slate-900">Adjust stock &mdash; {{ $product->name }}</h1>
            <p class="mt-1 font-mono text-sm text-slate-400">{{ $product->sku }}</p>

            <div class="mt-4 flex items-center gap-6 rounded-lg bg-slate-50 p-4">
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Current level</p>
                    <p class="text-2xl font-bold text-slate-900">{{ $product->stock }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Low stock at</p>
                    <p class="text-2xl font-bold text-slate-900">{{ $product->low_stock_threshold }}</p>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.inventory.adjust.store', $product) }}"
              class="rounded-xl border border-slate-200 bg-white p-6">
            @csrf

            <fieldset>
                <legend class="text-sm font-medium text-slate-700">What are you doing?</legend>

                <div class="mt-3 space-y-3">
                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50">
                        <input type="radio" name="action" value="set" @checked(old('action', 'set') === 'set')
                               class="mt-0.5 text-emerald-600 focus:ring-emerald-500">
                        <span>
                            <span class="block text-sm font-medium text-slate-800">Set counted quantity</span>
                            <span class="block text-xs text-slate-500">
                                Physical stock count. The quantity below becomes the new absolute level and the
                                difference from the recorded level is logged.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50">
                        <input type="radio" name="action" value="restock" @checked(old('action') === 'restock')
                               class="mt-0.5 text-emerald-600 focus:ring-emerald-500">
                        <span>
                            <span class="block text-sm font-medium text-slate-800">Restock (goods received)</span>
                            <span class="block text-xs text-slate-500">Adds the quantity to the current level.</span>
                        </span>
                    </label>

                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50">
                        <input type="radio" name="action" value="write_off" @checked(old('action') === 'write_off')
                               class="mt-0.5 text-emerald-600 focus:ring-emerald-500">
                        <span>
                            <span class="block text-sm font-medium text-slate-800">Write off (damaged, expired, lost)</span>
                            <span class="block text-xs text-slate-500">Removes the quantity from sellable stock.</span>
                        </span>
                    </label>
                </div>

                @error('action')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </fieldset>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <x-text-input name="quantity" label="Quantity" :value="old('quantity')"
                              type="number" step="1" min="0" required class="sm:col-span-1" />

                <x-text-input name="notes" label="Notes" :value="old('notes')"
                              hint="Recorded against the movement, e.g. supplier or delivery note reference." />
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <a href="{{ route('inventory.index') }}"
                   class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
                    Cancel
                </a>
                <button type="submit"
                        class="rounded-lg bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
                    Apply adjustment
                </button>
            </div>
        </form>
    </div>
@endsection
