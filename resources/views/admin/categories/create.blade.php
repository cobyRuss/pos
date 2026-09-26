@extends('layouts.app')

@section('title', 'New category')

@section('content')
    <a href="{{ route('admin.categories.index') }}" class="mb-4 inline-block text-sm text-slate-500 hover:text-slate-800">
        &larr; Back to categories
    </a>

    <h1 class="mb-6 text-xl font-bold text-slate-900">New category</h1>

    @include('admin.categories._form', ['category' => $category])
@endsection
