<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::in(UserRole::values())],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $query = User::query()->withCount('orders');

        if ($term = trim((string) ($validated['q'] ?? ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }

        if (! empty($validated['role'])) {
            $query->where('role', $validated['role']);
        }

        match ($validated['status'] ?? null) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            default => null,
        };

        return view('users.index', [
            'users' => $query->orderBy('name')->paginate(15)->withQueryString(),
            'filters' => $validated,
            'roles' => UserRole::options(),
        ]);
    }

    public function create(): View
    {
        return view('users.create', [
            'staff' => new User(['role' => UserRole::Staff, 'is_active' => true]),
            'roles' => UserRole::options(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = DB::transaction(fn () => User::create($request->validated()));

        AuditLogger::record(
            AuditLogger::USER_CREATED,
            sprintf('Created %s account for %s (%s).', $user->role->label(), $user->name, $user->email),
            $user,
            null,
            $user->only(['name', 'email', 'role', 'is_active']),
        );

        return redirect()
            ->route('admin.users.index')
            ->with('success', sprintf('Account created for %s.', $user->name));
    }

    public function edit(Request $request, User $user): View
    {
        return view('users.edit', [
            'staff' => $user,
            'roles' => UserRole::options(),
            'isSelf' => $user->getKey() === $request->user()->getKey(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $before = $user->only(['name', 'email', 'role', 'phone', 'address', 'is_active']);
        $data = $request->validated();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        DB::transaction(function () use ($user, $data) {
            $user->update($data);
        });

        $user->refresh();
        $diff = AuditLogger::diff($before, $user->only(array_keys($before)));

        AuditLogger::record(
            AuditLogger::USER_UPDATED,
            sprintf('Updated account for %s.', $user->name),
            $user,
            $diff['old'],
            $diff['new'],
        );

        return redirect()
            ->route('admin.users.index')
            ->with('success', sprintf('Account updated for %s.', $user->name));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $currentUser = $request->user();

        if ($user->getKey() === $currentUser->getKey()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($user->isAdmin() && User::where('role', UserRole::Admin)->where('is_active', true)->count() <= 1) {
            return back()->with('error', 'You cannot delete the last active administrator.');
        }

        $name = $user->name;
        $old = $user->only(['name', 'email', 'role', 'is_active']);

        AuditLogger::record(
            AuditLogger::USER_DELETED,
            sprintf('Deleted %s account for %s (%s).', $user->role->label(), $name, $user->email),
            $user,
            $old,
        );

        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', sprintf('Account deleted for %s.', $name));
    }
}
