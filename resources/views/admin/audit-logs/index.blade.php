@extends('layouts.app')

@section('title', 'Audit Log')

@section('content')
    <x-page-header title="Audit Log">
        <span class="text-sm text-slate-500">{{ $logs->total() }} {{ Str::plural('entry', $logs->total()) }}</span>
    </x-page-header>

    <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="mb-4 grid gap-2 rounded-xl border border-slate-200 bg-white p-3 sm:grid-cols-2 lg:grid-cols-5">
        <div>
            <x-text-input name="q" type="search" :value="request('q')" placeholder="Search description or action…" />
        </div>
        <div>
            <select name="action" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">Any action</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <select name="user_id" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">Anyone</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @selected((string) request('user_id') === (string) $user->id)>{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <input type="date" name="from" value="{{ request('from') }}"
                   class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
        </div>
        <div class="flex gap-2">
            <input type="date" name="to" value="{{ request('to') }}"
                   class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
            <button type="submit" class="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">Filter</button>
        </div>
    </form>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">When</th>
                    <th class="px-4 py-3">Who</th>
                    <th class="px-4 py-3">Action</th>
                    <th class="px-4 py-3">Detail</th>
                    <th class="px-4 py-3">Subject</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($logs as $log)
                    <tr class="hover:bg-slate-50">
                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-500">
                            {{ $log->created_at?->format('d M Y H:i') ?? '—' }}
                        </td>
                        <td class="px-4 py-2.5 text-slate-700">{{ $log->user?->name ?? 'System' }}</td>
                        <td class="px-4 py-2.5">
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-700">{{ $log->action }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-slate-600">{{ $log->description ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-xs text-slate-400">
                            @if ($log->model_type)
                                {{ class_basename($log->model_type) }} #{{ $log->model_id }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-400">
                            No audit entries match these filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
