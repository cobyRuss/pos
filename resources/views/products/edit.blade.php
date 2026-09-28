@extends('layouts.app')

@section('title', 'Edit '.$product->name)
@section('page-title', 'Edit Product')
@section('page-subtitle', $product->category?->name ?? 'Product')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="card mb-3">
            <div class="card-header">Product Details</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.products.update', $product) }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    @include('products._form')

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
                        <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="card">
            <div class="card-header">Quick Actions</div>
            <div class="list-group list-group-flush">
                <a href="{{ route('admin.inventory.create', $product) }}" class="list-group-item list-group-item-action">
                    <i class="bi bi-boxes me-2"></i>Adjust Stock
                    <span class="badge text-bg-light text-body-secondary float-end">{{ $product->stock }}</span>
                </a>
                <a href="{{ route('products.show', $product) }}" class="list-group-item list-group-item-action">
                    <i class="bi bi-eye me-2"></i>View Product
                </a>
                <a href="{{ route('admin.audit-logs.index') }}" class="list-group-item list-group-item-action">
                    <i class="bi bi-shield-check me-2"></i>Audit Trail
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
