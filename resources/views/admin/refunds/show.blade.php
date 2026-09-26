@extends('layouts.app')

@section('title', 'Refund #'.$refund->id)

@section('content')
    <x-page-header :title="'Refund #'.$refund->id">
        <a href="{{ route('admin.refunds.index') }}" class="text-sm text-slate-500 hover:text-slate-800">&larr; All refunds</a>
    </x-page-header>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Item</th>
                        <th class="px-4 py-3 text-right">Qty</th>
                        <th class="px-4 py-3">Condition</th>
                        <th class="px-4 py-3">Stock</th>
                        <th class="px-4 py-3 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($refund->items as $item)
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-800">{{ $item->product_name }}</td>
                            <td class="px-4 py-3 text-right text-slate-600">{{ $item->quantity }}</td>
                            <td class="px-4 py-3">
                                @if ($item->is_restockable)
                                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Restockable</span>
                                @else
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">Damaged / scrapped</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500">
                                {{ $item->restocked ? 'Returned to sellable stock' : 'Not returned to stock' }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900">{{ $item->amount }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="border-t border-slate-200 px-4 py-3 text-right">
                <span class="text-sm text-slate-500">Refunded</span>
                <span class="ml-2 text-lg font-bold text-slate-900">{{ $refund->total_amount }}</span>
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-2 text-sm font-semibold text-slate-900">Refund</h3>
                <dl class="space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Order</dt>
                        <dd><a href="{{ route('orders.show', $refund->order) }}" class="font-medium text-emerald-700 hover:underline">{{ $refund->order?->order_number ?? '—' }}</a></dd>
                    </div>
                    <div class="flex justify-between"><dt class="text-slate-500">Processed by</dt><dd class="font-medium">{{ $refund->user?->name ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">When</dt><dd class="font-medium">{{ $refund->created_at->format('d M Y H:i') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Payout</dt><dd class="font-medium">{{ ucfirst($refund->method) }}</dd></div>
                    @if ($refund->reference_no)
                        <div class="flex justify-between"><dt class="text-slate-500">Reference</dt><dd class="font-medium">{{ $refund->reference_no }}</dd></div>
                    @endif
                </dl>

                @if ($refund->reason)
                    <div class="mt-3 border-t border-slate-200 pt-3">
                        <p class="text-xs font-medium text-slate-500">Reason</p>
                        <p class="mt-1 text-sm text-slate-700">{{ $refund->reason }}</p>
                    </div>
                @endif
            </div>

            @if ($refund->order)
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 class="mb-2 text-sm font-semibold text-slate-900">Original order</h3>
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Total</dt><dd class="font-medium">{{ $refund->order->total }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Refunded so far</dt><dd class="font-medium text-rose-600">{{ $refund->order->refunded_total }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Net</dt><dd class="font-medium">{{ $refund->order->net_total }}</dd></div>
                    </dl>
                </div>
            @endif
        </div>
    </div>
@endsection
