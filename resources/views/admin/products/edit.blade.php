@extends('layouts.app')

@section('title', 'Edit '.$product->name)

@section('content')
    <a href="{{ route('admin.products.index') }}" class="mb-4 inline-block text-sm text-slate-500 hover:text-slate-800">
        &larr; Back to products
    </a>

    <h1 class="mb-6 text-xl font-bold text-slate-900">Edit {{ $product->name }}</h1>

    @include('admin.products._form', ['product' => $product])
@endsection
