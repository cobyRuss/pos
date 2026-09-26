<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'Your account has been deactivated. Please contact an administrator.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
            }

            return redirect()->route('login')->with('error', $message);
        }

        return $next($request);
    }
}
