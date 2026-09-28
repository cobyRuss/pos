<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Support\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to the given role(s).
 *
 * Usage: ->middleware('role:admin') or ->middleware('role:admin,staff')
 *
 * Unauthorized access redirects back with a clear error message rather than
 * failing silently.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'))
                ->with('error', 'Please sign in to continue.');
        }

        $allowed = array_filter(array_map(
            fn (string $role) => UserRole::tryFrom(strtolower(trim($role)))?->value,
            $roles
        ));

        if ($allowed === []) {
            abort(500, 'No valid role supplied to the [role] middleware.');
        }

        $current = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;

        if (! in_array($current, $allowed, true)) {
            AuditLogger::record(
                AuditLogger::ACCESS_DENIED,
                sprintf('%s was denied access to %s %s (requires: %s).', $user->name, $request->method(), $request->path(), implode(', ', $allowed)),
            );

            $message = match ($current) {
                UserRole::Staff->value => 'You do not have permission to access that area. Administrator access is required.',
                UserRole::Admin->value => 'Selling is limited to cashier accounts. Ask a staff member to ring up this sale.',
                default => 'You do not have permission to perform that action.',
            };

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
            }

            return redirect()
                ->route($current === UserRole::Admin->value ? 'admin.dashboard' : 'pos.index')
                ->with('error', $message);
        }

        return $next($request);
    }
}
