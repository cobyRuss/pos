<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'action' => ['nullable', 'string', 'max:60'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = AuditLog::query()->with('user');

        if ($term = trim((string) ($validated['q'] ?? ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(fn ($q) => $q->where('description', 'like', $like)->orWhere('user_name', 'like', $like));
        }

        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        $actions = AuditLog::query()
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->mapWithKeys(fn (string $action) => [$action => $action])
            ->all();

        return view('audit-logs.index', [
            'logs' => $query->latest()->paginate(20)->withQueryString(),
            'filters' => $validated,
            'actions' => $actions,
            'users' => User::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function show(AuditLog $auditLog): View
    {
        return view('audit-logs.show', [
            'log' => $auditLog->load(['user', 'auditable']),
        ]);
    }
}
