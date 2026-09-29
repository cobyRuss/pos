@extends('layouts.app')

@section('title', 'Audit Logs')
@section('page-title', 'Audit Logs')
@section('page-subtitle', 'Every sensitive action, who did it and when')

@section('content')
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="q" class="form-label small fw-semibold">Search</label>
                <input type="search" class="form-control" id="q" name="q" value="{{ $filters['q'] ?? '' }}"
                       placeholder="Description or user…">
            </div>
            <div class="col-md-3">
                <label for="action" class="form-label small fw-semibold">Action</label>
                <select class="form-select" id="action" name="action">
                    <option value="">All actions</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>
                            {{ str_replace('_', ' ', $action) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="user_id" class="form-label small fw-semibold">User</label>
                <select class="form-select" id="user_id" name="user_id">
                    <option value="">Everyone</option>
                    @foreach ($users as $id => $name)
                        <option value="{{ $id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <label for="from" class="form-label small fw-semibold">From</label>
                <input type="date" class="form-control" id="from" name="from" value="{{ $filters['from'] ?? '' }}">
            </div>
            <div class="col-md-1">
                <label for="to" class="form-label small fw-semibold">To</label>
                <input type="date" class="form-control" id="to" name="to" value="{{ $filters['to'] ?? '' }}">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100" title="Filter"><i class="bi bi-funnel"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">Entries ({{ $logs->total() }})</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>When</th>
                <th>Actor</th>
                <th>Action</th>
                <th>Description</th>
                <th>Target</th>
                <th>IP</th>
                <th class="text-end">Details</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td class="text-nowrap">{{ \App\Support\DateFormat::dateTimeSeconds($log->created_at) }}</td>
                    <td class="fw-semibold">{{ $log->actor }}</td>
                    <td>
                        <span class="badge {{ $log->action_badge_class }}">{{ str_replace('_', ' ', $log->action) }}</span>
                    </td>
                    <td class="small">{{ $log->description }}</td>
                    <td class="small text-body-secondary">
                        @if ($log->auditable_type)
                            {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="small text-body-secondary">{{ $log->ip_address ?? '—' }}</td>
                    <td class="text-end">
                        <a href="{{ route('admin.audit-logs.show', $log) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-body-secondary py-4">
                        <i class="bi bi-shield-check fs-3 d-block mb-2"></i>
                        No audit entries match your filters.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($logs->hasPages())
        <div class="card-footer">{{ $logs->links() }}</div>
    @endif
</div>
@endsection
