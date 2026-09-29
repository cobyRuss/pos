@extends('layouts.app')

@section('title', 'Edit '.$staff->name)
@section('page-title', 'Edit User')
@section('page-subtitle', $staff->email)

@section('content')
<div class="row justify-content-center g-3">
    <div class="col-lg-9">
        <div class="card">
            <div class="card-header">Account details</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.users.update', $staff) }}">
                    @csrf
                    @method('PUT')
                    @include('users._form')

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
                        <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="card">
            <div class="card-header">Summary</div>
            <div class="card-body text-center">
                <span class="avatar avatar-lg mb-2">{{ $staff->initials() }}</span>
                <div class="fw-semibold">{{ $staff->name }}</div>
                <div class="small text-body-secondary">{{ $staff->role->label() }}</div>
                <hr>
                <dl class="row mb-0 small text-start">
                    <dt class="col-7 text-body-secondary fw-normal">Orders</dt>
                    <dd class="col-5 text-end">{{ $staff->orders_count }}</dd>

                    <dt class="col-7 text-body-secondary fw-normal">Refunds</dt>
                    <dd class="col-5 text-end">{{ $staff->refunds()->count() }}</dd>

                    <dt class="col-7 text-body-secondary fw-normal">Stock moves</dt>
                    <dd class="col-5 text-end">{{ $staff->inventoryMovements()->count() }}</dd>

                    <dt class="col-7 text-body-secondary fw-normal">Last login</dt>
                    <dd class="col-5 text-end">
                        {{ \App\Support\DateFormat::dateTime($staff->last_login_at) ?? 'Never' }}
                    </dd>

                    <dt class="col-7 text-body-secondary fw-normal">Created</dt>
                    <dd class="col-5 text-end">{{ \App\Support\DateFormat::date($staff->created_at) }}</dd>
                </dl>
            </div>
            @if ($isSelf)
                <div class="card-footer bg-body-tertiary small text-body-secondary">
                    <i class="bi bi-info-circle me-1"></i>
                    This is your own account. Your role and status are locked to prevent lock-out.
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
