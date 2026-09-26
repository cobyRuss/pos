@extends('layouts.app')

@section('title', 'Refunds')

@section('content')
    <x-page-header title="Refunds &amp; Returns">
        <span class="text-sm text-slate-500">{{ $refunds->total() }} {{ Str::plural('refund', $refunds->total()) }}</span>
    </x-page-header>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Refund</th>
                    <th class="px-4 py-3">Order</th>
                    <th class="px-4 py-3">When</th>
                    <th class="px-4 py-3">By</th>
                    <th class="px-4 py-3">Payout</th>
                    <th class="px-4 py-3">Items</th>
                    <th class="px-4 py-3 text-right">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($refunds as $refund)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.refunds.show', $refund) }}" class="font-medium text-emerald-700 hover:underline">
                                #{{ $refund->id }}
                            </a>
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('orders.show', $refund->order) }}" class="text-slate-700 hover:underline">
                                {{ $refund->order?->order_number ?? '—' }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $refund->created_at->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $refund->user?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ ucfirst($refund->method) }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $refund->items->sum('quantity') }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-slate-900">{{ $refund->total_amount }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-slate-400">
                            No refunds recorded yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $refunds->links() }}</div>
@endsection
