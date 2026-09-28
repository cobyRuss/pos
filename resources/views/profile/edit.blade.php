@extends('layouts.app')

@section('title', 'Edit Profile')
@section('page-title', 'Edit Profile')
@section('page-subtitle', 'Update your personal details')

@section('content')
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Profile Information</div>
            <div class="card-body">
                <form method="POST" action="{{ route('profile.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror"
                               id="name" name="name" value="{{ old('name', $user->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror"
                               id="email" name="email" value="{{ old('email', $user->email) }}" required>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone</label>
                        <input type="text" class="form-control @error('phone') is-invalid @enderror"
                               id="phone" name="phone" value="{{ old('phone', $user->phone) }}">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-4">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control @error('address') is-invalid @enderror" id="address"
                                  name="address" rows="3">{{ old('address', $user->address) }}</textarea>
                        @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
                        <a href="{{ route('profile.password.edit') }}" class="btn btn-outline-secondary">Change Password</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">Account</div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="avatar" style="width:52px;height:52px;font-size:1.1rem;">{{ $user->initials() }}</span>
                    <div class="min-w-0">
                        <div class="fw-semibold text-truncate">{{ $user->name }}</div>
                        <div class="text-body-secondary small text-truncate">{{ $user->email }}</div>
                    </div>
                </div>

                <dl class="row mb-0 small">
                    <dt class="col-5 text-body-secondary fw-normal">Role</dt>
                    <dd class="col-7">
                        <span class="badge {{ $user->role->badgeClass() }}">{{ $user->role->label() }}</span>
                    </dd>

                    <dt class="col-5 text-body-secondary fw-normal">Status</dt>
                    <dd class="col-7">
                        @if ($user->is_active)
                            <span class="badge text-bg-success">Active</span>
                        @else
                            <span class="badge text-bg-secondary">Deactivated</span>
                        @endif
                    </dd>

                    <dt class="col-5 text-body-secondary fw-normal">Last sign-in</dt>
                    <dd class="col-7">{{ $user->last_login_at?->diffForHumans() ?? 'This is your first session' }}</dd>

                    <dt class="col-5 text-body-secondary fw-normal">Orders processed</dt>
                    <dd class="col-7">{{ $user->orders()->count() }}</dd>
                </dl>
            </div>
            <div class="card-footer bg-body-tertiary small text-body-secondary">
                Your role is managed by an administrator.
            </div>
        </div>
    </div>
</div>
@endsection
