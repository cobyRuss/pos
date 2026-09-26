<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->fill($request->safe()->only(['name', 'email', 'phone']));

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(ChangePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Must run before the new hash is written: logoutOtherDevices()
        // validates the supplied password against the stored hash.
        Auth::logoutOtherDevices($request->validated('current_password'));

        $user->password = Hash::make($request->validated('password'));
        $user->save();

        return back()->with('success', 'Password changed. All other sessions have been signed out.');
    }

    /**
     * Confirm the current password before revealing sensitive screens.
     */
    public function confirmPassword(Request $request): RedirectResponse
    {
        if (! Hash::check($request->validated('password'), $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => 'The provided password is incorrect.',
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return back()->with('success', 'Password confirmed.');
    }
}
