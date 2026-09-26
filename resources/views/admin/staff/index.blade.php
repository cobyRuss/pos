@extends('layouts.app')

@section('title', 'Staff')

@section('content')
    <x-page-header title="Staff">
        <a href="{{ route('admin.staff.create') }}"
           class="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
            New account
        </a>
    </x-page-header>

    <form method="GET" action="{{ route('admin.staff.index') }}" class="mb-4 grid gap-2 rounded-xl border border-slate-200 bg-white p-3 sm:grid-cols-4">
        <div>
            <x-text-input name="q" type="search" :value="request('q')" placeholder="Name or email…" />
        </div>
        <div>
            <select name="role" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">Any role</option>
                @foreach ($roleOptions as $value => $label)
                    <option value="{{ $value }}" @selected(request('role') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <select name="status" class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">Any status</option>
                <option value="active" @selected(request('status') === 'active')>Active</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">Filter</button>
            <a href="{{ route('admin.staff.index') }}" class="py-2 text-sm text-slate-500 hover:text-slate-800">Reset</a>
        </div>
    </form>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Role</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Last sign-in</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($staff as $member)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <p class="font-medium text-slate-900">{{ $member->name }}</p>
                            <p class="text-xs text-slate-500">{{ $member->email }}</p>
                        </td>
                        <td class="px-4 py-3">
                            @php($role = $member->getRoleNames()->first())
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $role === 'admin' ? 'bg-violet-100 text-violet-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ ucfirst($role ?? 'None') }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @if ($member->is_active)
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Active</span>
                            @else
                                <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-500">
                            {{ $member->last_login_at?->format('d M Y H:i') ?? 'Never' }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-3">
                                <a href="{{ route('admin.staff.edit', $member) }}"
                                   class="font-medium text-emerald-700 hover:underline">Edit</a>

                                @if ((int) $member->id !== (int) auth()->id())
                                    <form method="POST" action="{{ route('admin.staff.destroy', $member) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-sm {{ $member->is_active ? 'text-amber-700 hover:underline' : 'text-emerald-700 hover:underline' }}">
                                            {{ $member->is_active ? 'Deactivate' : 'Reactivate' }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-400">No accounts match these filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $staff->links() }}</div>
@endsection
