<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $logs = AuditLog::query()
            ->with('user')
            ->when($filters['q'] ?? null, function ($query) use ($filters): void {
                $like = '%'.$filters['q'].'%';
                $query->where(fn ($q) => $q->where('description', 'like', $like)->orWhere('action', 'like', $like));
            })
            ->when($filters['action'] ?? null, fn ($query) => $query->ofAction($filters['action']))
            ->when($filters['user_id'] ?? null, fn ($query) => $query->forUser($filters['user_id']))
            ->when($filters['from'] ?? null, fn ($query) => $query->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'] ?? null, fn ($query) => $query->whereDate('created_at', '<=', $filters['to']))
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.audit-logs.index', [
            'logs' => $logs,
            'filters' => $filters,
            'users' => User::orderBy('name')->get(['id', 'name']),
            // Only actions actually present are offered, so the filter list
            // does not fill with dead options on a fresh install.
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
