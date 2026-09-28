@extends('layouts.app')

@section('title', 'Audit Entry')
@section('page-title', 'Audit Entry')
@section('page-subtitle', str_replace('_', ' ', $log->action).' — '.$log->created_at->format('d M Y H:i:s'))

@section('content')
<div class="d-flex gap-2 mb-3">
    <a href="{{ route('admin.audit-logs.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Audit Logs
    </a>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">Event</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-body-secondary fw-normal">Action</dt>
                    <dd class="col-7">
                        <span class="badge {{ $log->action_badge_class }}">{{ str_replace('_', ' ', $log->action) }}</span>
                    </dd>

                    <dt class="col-5 text-body-secondary fw-normal">Actor</dt>
                    <dd class="col-7">
                        @if ($log->user)
                            <a href="{{ route('admin.users.edit', $log->user) }}" class="text-decoration-none">{{ $log->user->name }}</a>
                            <div class="text-body-secondary">{{ $log->user->email }}</div>
                        @else
                            {{ $log->actor }}
                        @endif
                    </dd>

                    <dt class="col-5 text-body-secondary fw-normal">When</dt>
                    <dd class="col-7">{{ $log->created_at->format('d/m/Y H:i:s') }}<div class="text-body-secondary">{{ $log->created_at->diffForHumans() }}</div></dd>

                    <dt class="col-5 text-body-secondary fw-normal">Target</dt>
                    <dd class="col-7">
                        @if ($log->auditable_type)
                            {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                            @if ($log->auditable)
                                <div class="text-body-secondary">{{ $log->auditable->name ?? $log->auditable->order_number ?? '—' }}</div>
                            @endif
                        @else
                            —
                        @endif
                    </dd>

                    <dt class="col-5 text-body-secondary fw-normal">IP address</dt>
                    <dd class="col-7">{{ $log->ip_address ?? '—' }}</dd>
                </dl>
            </div>
            <div class="card-footer bg-body-tertiary small">
                <div class="fw-semibold mb-1">User agent</div>
                <div class="text-break text-body-secondary">{{ $log->user_agent ?? '—' }}</div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">Description</div>
            <div class="card-body">{{ $log->description ?: '—' }}</div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">Values before</div>
                    <div class="card-body">
                        @if (empty($log->old_values))
                            <p class="text-body-secondary mb-0"><i class="bi bi-dash-circle"></i> No previous values recorded.</p>
                        @else
                            <table class="table table-sm mb-0">
                                <tbody>
                                @foreach ($log->old_values as $field => $value)
                                    <tr>
                                        <th class="text-body-secondary fw-normal small text-nowrap">{{ $field }}</th>
                                        <td class="small text-break">{{ is_scalar($value) || $value === null ? ($value ?? 'null') : json_encode($value) }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">Values after</div>
                    <div class="card-body">
                        @if (empty($log->new_values))
                            <p class="text-body-secondary mb-0"><i class="bi bi-dash-circle"></i> No new values recorded.</p>
                        @else
                            <table class="table table-sm mb-0">
                                <tbody>
                                @foreach ($log->new_values as $field => $value)
                                    <tr>
                                        <th class="text-body-secondary fw-normal small text-nowrap">{{ $field }}</th>
                                        <td class="small text-break">{{ is_scalar($value) || $value === null ? ($value ?? 'null') : json_encode($value) }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
