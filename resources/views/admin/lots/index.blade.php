@extends('layouts.app')

@section('title', 'Batches · '.$product->name)

@section('content')
    <a href="{{ route('inventory.index') }}" class="mb-4 inline-block text-sm text-slate-500 hover:text-slate-800">
        &larr; Back to inventory
    </a>

    <x-page-header :title="'Batches · '.$product->name">
        @if ($expiredCount > 0)
            <span class="rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-700">
                {{ $expiredCount }} expired
            </span>
        @endif
        @if ($expiringSoon > 0)
            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">
                {{ $expiringSoon }} expiring within 30 days
            </span>
        @endif
        <a href="{{ route('admin.lots.create', $product) }}"
           class="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
            Receive delivery
        </a>
    </x-page-header>

    <p class="mb-4 text-sm text-slate-500">
        Sales draw from the batch closest to its expiry date first (FEFO). A product that does not
        track expiry is not tracked batch by batch — its single stock count is the whole story.
    </p>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Batch</th>
                    <th class="px-4 py-3">Expires</th>
                    <th class="px-4 py-3 text-right">Quantity</th>
                    <th class="px-4 py-3 text-right">Cost</th>
                    <th class="px-4 py-3 text-right">Value</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($lots as $lot)
                    <tr @class(['opacity-50' => $lot->quantity === 0])>
                        <td class="px-4 py-3 font-medium text-slate-800">
                            {{ $lot->code }}
                            @if ($lot->code === \App\Services\LotAllocator::RECOVERY_CODE)
                                <span class="ml-1 rounded bg-violet-100 px-1.5 py-0.5 text-xs text-violet-700">holding</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($lot->expires_at === null)
                                <span class="text-slate-400">No expiry</span>
                            @elseif ($lot->is_expired)
                                <span class="font-semibold text-rose-600">Expired {{ $lot->expires_at->format('d M Y') }}</span>
                            @elseif ($lot->is_expiring_soon)
                                <span class="font-semibold text-amber-600">{{ $lot->expires_at->format('d M Y') }} ({{ $lot->days_until_expiry }}d)</span>
                            @else
                                <span class="text-slate-600">{{ $lot->expires_at->format('d M Y') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-medium text-slate-900">{{ $lot->quantity }}</td>
                        <td class="px-4 py-3 text-right text-slate-600">{{ $lot->cost ?? '—' }}</td>
                        <td class="px-4 py-3 text-right text-slate-600">
                            {{ $lot->cost ? number_format((float) $lot->cost * $lot->quantity, 2) : '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-3">
                                <a href="{{ route('admin.lots.edit', [$product, $lot]) }}" class="font-medium text-emerald-700 hover:underline">Count</a>
                                @if ($lot->quantity === 0)
                                    <form method="POST" action="{{ route('admin.lots.destroy', [$product, $lot]) }}"
                                          onsubmit="return confirm('Delete empty batch {{ $lot->code }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-sm text-rose-600 hover:underline">Delete</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-400">
                            No batches yet. Receive a delivery to record its expiry date.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
