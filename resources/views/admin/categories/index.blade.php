@extends('layouts.app')

@section('title', 'Categories')

@section('content')
    <x-page-header title="Categories">
        <a href="{{ route('admin.categories.create') }}"
           class="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-emerald-400">
            New category
        </a>
    </x-page-header>

    <div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Slug</th>
                    <th class="px-4 py-3 text-right">Products</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($categories as $category)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <p class="font-medium text-slate-900">{{ $category->name }}</p>
                            @if ($category->description)
                                <p class="text-xs text-slate-500">{{ Str::limit($category->description, 80) }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $category->slug }}</td>
                        <td class="px-4 py-3 text-right text-slate-600">{{ $category->products_count }}</td>
                        <td class="px-4 py-3">
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-semibold',
                                'bg-emerald-100 text-emerald-700' => $category->is_active,
                                'bg-slate-100 text-slate-600' => ! $category->is_active,
                            ])>{{ $category->is_active ? 'Active' : 'Hidden' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-3 text-sm">
                                <a href="{{ route('admin.categories.edit', $category) }}"
                                   class="font-medium text-emerald-700 hover:text-emerald-900">Edit</a>
                                <form method="POST" action="{{ route('admin.categories.destroy', $category) }}"
                                      onsubmit="return confirm('Delete {{ $category->name }}? Products in it will become uncategorised.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="font-medium text-rose-600 hover:text-rose-800">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                            No categories yet.
                            <a href="{{ route('admin.categories.create') }}" class="font-medium text-emerald-700 hover:underline">
                                Create the first category
                            </a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $categories->links() }}</div>
@endsection
