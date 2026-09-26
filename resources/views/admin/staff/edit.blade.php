@extends('layouts.app')

@section('title', 'Edit '.$member->name)

@section('content')
    <a href="{{ route('admin.staff.index') }}" class="mb-4 inline-block text-sm text-slate-500 hover:text-slate-800">
        &larr; Back to staff
    </a>

    <h1 class="mb-6 text-xl font-bold text-slate-900">Edit {{ $member->name }}</h1>

    @include('admin.staff._form')
@endsection
