@extends('layouts.app')

@section('title', 'Stock movement log')

@section('content')
    <x-page-header title="Stock movement log">
        <a href="{{ route('inventory.index') }}"
           class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Back to inventory
        </a>
    </x-page-header>

    <p class="mt-1 text-sm text-slate-500">
        Append-only record of every stock change. Entries cannot be edited or removed.
    </p>

    <form method="GET" action="{{ route('inventory.logs') }}"
          class="mt-4 mb-4 grid items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-6">
        <div class="lg:col-span-2">
            <x-select-input name="product" label="Product" :value="$filters['product'] ?? null"
                            :options="$products->pluck('name', 'id')" include-blank blank-label="All products" />
        </div>

        <x-select-input name="type" label="Type" :value="$filters['type'] ?? null"
                        :options="[
                            'in' => 'Stock in',
                            'out' => 'Stock out',
                            'adjustment' => 'Adjustment',
                        ]"
                        include-blank blank-label="All types" />

        <x-text-input name="from" label="From" type="date" :value="$filters['from'] ?? null" />

        <x-text-input name="to" label="To" type="date" :value="$filters['to'] ?? null" />

        <div class="flex items-center gap-2">
            <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                Filter
            </button>
            @if (array_filter($filters))
                <a href="{{ route('inventory.logs') }}"
                   class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
                    Reset
                </a>
            @endif
        </div>
    </form>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">When</th>
                    <th class="px-4 py-3">Product</th>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3 text-right">Change</th>
                    <th class="px-4 py-3">By</th>
                    <th class="px-4 py-3">Notes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($logs as $log)
                    <tr class="hover:bg-slate-50">
                        <td class="whitespace-nowrap px-4 py-3 text-slate-500">
                            {{ $log->created_at?->format('d M Y H:i') }}
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium text-slate-900">{{ $log->product?->name ?? 'Deleted product' }}</p>
                            <p class="font-mono text-xs text-slate-400">{{ $log->product?->sku }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-semibold',
                                'bg-emerald-100 text-emerald-700' => $log->type === 'in',
                                'bg-rose-100 text-rose-700' => $log->type === 'out',
                                'bg-amber-100 text-amber-700' => $log->type === 'adjustment',
                            ])>{{ str_replace('_', ' ', $log->type) }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-semibold">
                            <span class="{{ $log->quantity_change > 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                {{ $log->quantity_change > 0 ? '+' : '' }}{{ $log->quantity_change }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $log->user?->name ?? 'System' }}</td>
                        <td class="px-4 py-3 text-slate-500">
                            {{ $log->notes ?: '—' }}
                            @if ($log->reference_type)
                                <span class="block text-xs text-slate-400">
                                    {{ class_basename($log->reference_type) }} #{{ $log->reference_id }}
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                            No stock movements recorded yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
