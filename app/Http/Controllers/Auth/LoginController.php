<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->only('email', 'password');

        $user = User::where('email', $credentials['email'])->first();

        // Reject inactive accounts with a clear message rather than a generic failure.
        if ($user && ! $user->is_active) {
            AuditLogger::record(
                AuditLogger::FAILED_LOGIN,
                sprintf('Blocked sign-in for deactivated account %s.', $credentials['email']),
            );

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'This account has been deactivated. Please contact an administrator.']);
        }

        $request->authenticate();

        $request->session()->regenerate();

        DB::transaction(function () use ($user) {
            $user->forceFill(['last_login_at' => now()])->save();
        });

        AuditLogger::record(
            AuditLogger::LOGIN,
            sprintf('%s signed in as %s.', $user->name, $user->role->label()),
        );

        return redirect()->intended($user->isAdmin() ? route('admin.dashboard') : route('pos.index'))
            ->with('success', sprintf('Welcome back, %s!', $user->name));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user) {
            AuditLogger::record(AuditLogger::LOGOUT, sprintf('%s signed out.', $user->name));
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You have been signed out.');
    }
}
