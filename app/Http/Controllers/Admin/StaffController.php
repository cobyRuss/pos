<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffRequest;
use App\Http\Requests\Admin\UpdateStaffRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Staff accounts.
 *
 * Accounts are deactivated rather than deleted. An order, refund or stock
 * movement is a financial record that must keep pointing at the person who
 * made it, and the schema restricts deletion of those rows on purpose. The one
 * exception is an account that has never touched the till, which cannot have
 * any history worth preserving.
 */
class StaffController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', Rule::in(Role::values())],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $staff = User::query()
            ->when($filters['q'] ?? null, function ($query) use ($filters): void {
                $like = '%'.$filters['q'].'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when($filters['role'] ?? null, fn ($query) => $query->whereHas(
                'roles',
                fn ($q) => $q->where('name', $filters['role']),
            ))
            ->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($query) => $query->where('is_active', false))
            ->with('roles')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.staff.index', [
            'staff' => $staff,
            'roleOptions' => Role::options(),
        ]);
    }

    public function create(): View
    {
        return view('admin.staff.create', [
            'roleOptions' => Role::options(),
            'roles' => collect(Role::cases())->mapWithKeys(
                fn (Role $role) => [$role->value => ['label' => $role->label(), 'description' => $role->description()]],
            ),
        ]);
    }

    public function store(StoreStaffRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::create([
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'phone' => $request->input('phone'),
                'password' => $request->validated('password'),
                'is_active' => (bool) $request->input('is_active', true),
            ]);

            $user->assignRole($request->string('role')->toString());

            return $user;
        });

        $this->audit->record(
            AuditLogger::STAFF_CREATED,
            $user,
            $request->user()->getKey(),
            "Created staff account {$user->email} with role {$request->string('role')}.",
        );

        return redirect()
            ->route('admin.staff.index')
            ->with('success', "Staff account created for {$user->name}.");
    }

    public function edit(User $user): View
    {
        return view('admin.staff.edit', [
            'member' => $user,
            'roleOptions' => Role::options(),
            'roles' => collect(Role::cases())->mapWithKeys(
                fn (Role $role) => [$role->value => ['label' => $role->label(), 'description' => $role->description()]],
            ),
        ]);
    }

    public function update(UpdateStaffRequest $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        // An admin who removes their own admin role, or deactivates their own
        // account, would lock the shop out of its own administration.
        if ((int) $user->getKey() === (int) $actor->getKey()) {
            $losingAdmin = $request->string('role')->toString() !== Role::Admin->value;
            $deactivating = ! (bool) $request->input('is_active', true);

            if ($losingAdmin || $deactivating) {
                return back()->withInput()->with('error', 'You cannot remove your own admin access or deactivate yourself.');
            }
        }

        DB::transaction(function () use ($request, $user): void {
            $user->fill([
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'phone' => $request->input('phone'),
                'is_active' => (bool) $request->input('is_active', true),
            ]);

            if ($request->filled('password')) {
                $user->password = $request->validated('password');
            }

            $user->save();

            $user->syncRoles([$request->string('role')->toString()]);
        });

        $this->audit->record(
            AuditLogger::STAFF_UPDATED,
            $user,
            $actor->getKey(),
            sprintf(
                'Updated staff account %s (role %s, %s).',
                $user->email,
                $request->string('role'),
                $user->is_active ? 'active' : 'inactive',
            ),
        );

        return redirect()
            ->route('admin.staff.index')
            ->with('success', "Staff account for {$user->name} updated.");
    }

    /**
     * Deactivate an account. This is the normal way to remove someone: their
     * sales history stays intact and their sessions stop working immediately.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        if ((int) $user->getKey() === (int) $actor->getKey()) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        if ($user->is_active) {
            $user->forceFill(['is_active' => false])->save();

            $this->audit->record(
                AuditLogger::STAFF_UPDATED,
                $user,
                $actor->getKey(),
                "Deactivated staff account {$user->email}.",
            );

            return back()->with('success', "{$user->name} has been deactivated and can no longer sign in.");
        }

        $user->forceFill(['is_active' => true])->save();

        $this->audit->record(
            AuditLogger::STAFF_UPDATED,
            $user,
            $actor->getKey(),
            "Reactivated staff account {$user->email}.",
        );

        return back()->with('success', "{$user->name} has been reactivated.");
    }

    /**
     * Permanently remove an account that has no till history. Anything with
     * orders, refunds or stock movements against it must be deactivated
     * instead, so the financial records keep their author.
     */
    public function destroyPermanently(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        if ((int) $user->getKey() === (int) $actor->getKey()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $hasHistory = $user->orders()->exists()
            || $user->refunds()->exists()
            || $user->inventoryLogs()->exists();

        if ($hasHistory) {
            return back()->with('error', sprintf(
                '%s has sales or stock history, so the account must be deactivated rather than deleted.',
                $user->name,
            ));
        }

        $email = $user->email;

        try {
            DB::transaction(function () use ($user, $actor, $email): void {
                $user->delete();

                $this->audit->record(
                    AuditLogger::STAFF_UPDATED,
                    null,
                    $actor->getKey(),
                    "Deleted staff account {$email} (no till history).",
                );
            });
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$email} was deleted.");
    }
}
