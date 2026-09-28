@extends('layouts.app')

@section('title', 'Users & Staff')
@section('page-title', 'Users & Staff')
@section('page-subtitle', 'Accounts that can sign in, with their roles and activity')

@section('content')
<div class="d-flex justify-content-end mb-3">
    <a href="{{ route('admin.users.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-person-plus me-1"></i>New User
    </a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.users.index') }}" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label for="q" class="form-label small fw-semibold">Search</label>
                <input type="search" class="form-control" id="q" name="q" value="{{ $filters['q'] ?? '' }}"
                       placeholder="Name or email…">
            </div>
            <div class="col-md-3">
                <label for="role" class="form-label small fw-semibold">Role</label>
                <select class="form-select" id="role" name="role">
                    <option value="">All roles</option>
                    @foreach ($roles as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['role'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label small fw-semibold">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                    <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100" title="Filter"><i class="bi bi-funnel"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">Accounts ({{ $users->total() }})</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>User</th>
                <th>Role</th>
                <th>Contact</th>
                <th class="text-center">Orders</th>
                <th>Last login</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($users as $user)
                @php $isSelf = $user->getKey() === auth()->id(); @endphp
                <tr class="{{ $user->is_active ? '' : 'table-secondary' }}">
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="avatar avatar-sm">{{ $user->initials() }}</span>
                            <div class="min-w-0">
                                <div class="fw-semibold text-truncate">
                                    {{ $user->name }}
                                    @if ($isSelf)
                                        <span class="badge text-bg-light text-body-secondary">You</span>
                                    @endif
                                </div>
                                <div class="text-body-secondary small text-truncate">{{ $user->email }}</div>
                            </div>
                        </div>
                    </td>
                    <td><span class="badge {{ $user->role->badgeClass() }}">{{ $user->role->label() }}</span></td>
                    <td class="small text-body-secondary">
                        {{ $user->phone ?: '—' }}
                        @if ($user->address)
                            <div class="text-truncate" style="max-width: 220px;">{{ $user->address }}</div>
                        @endif
                    </td>
                    <td class="text-center">{{ $user->orders_count }}</td>
                    <td class="small text-nowrap">
                        @if ($user->last_login_at)
                            {{ $user->last_login_at->diffForHumans() }}
                            <div class="text-body-secondary">{{ $user->last_login_at->format('d/m/Y H:i') }}</div>
                        @else
                            <span class="text-body-secondary">Never</span>
                        @endif
                    </td>
                    <td>
                        @if ($user->is_active)
                            <span class="badge text-bg-success">Active</span>
                        @else
                            <span class="badge text-bg-secondary">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        @unless ($isSelf)
                            <form method="POST" action="{{ route('admin.users.destroy', $user) }}" class="d-inline"
                                  onsubmit="return confirm('Delete the account for {{ addslashes($user->name) }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-body-secondary py-4">
                        <i class="bi bi-people fs-3 d-block mb-2"></i>
                        No users match your filters.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($users->hasPages())
        <div class="card-footer">{{ $users->links() }}</div>
    @endif
</div>
@endsection
