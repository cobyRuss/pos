@extends('layouts.app')

@section('title', 'Categories')
@section('page-title', 'Categories')
@section('page-subtitle', 'Organise your product catalog')

@section('content')
<div class="d-flex justify-content-end mb-3">
    <a href="{{ route('admin.categories.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>New Category
    </a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Name</th>
                <th>Description</th>
                <th class="text-center">Products</th>
                <th class="text-center">Units in Stock</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($categories as $category)
                <tr>
                    <td class="fw-semibold">{{ $category->name }}</td>
                    <td class="text-body-secondary small">{{ $category->description ?: '—' }}</td>
                    <td class="text-center">
                        <a href="{{ route('products.index', ['category_id' => $category->id]) }}" class="text-decoration-none">
                            {{ $category->products_count }}
                        </a>
                    </td>
                    <td class="text-center">{{ (int) $category->products_stock }}</td>
                    <td>
                        @if ($category->is_active)
                            <span class="badge text-bg-success">Active</span>
                        @else
                            <span class="badge text-bg-secondary">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('admin.categories.edit', $category) }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" class="d-inline"
                              onsubmit="return confirm('Delete category {{ addslashes($category->name) }}?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-body-secondary py-4">
                        <i class="bi bi-tags fs-3 d-block mb-2"></i>
                        No categories yet. Create your first one.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
