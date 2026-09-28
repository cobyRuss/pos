<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
        $before = $user->only(['name', 'email', 'phone', 'address']);
        $data = $request->validated();

        $user->fill($data)->save();

        $diff = AuditLogger::diff($before, $user->only(['name', 'email', 'phone', 'address']));

        AuditLogger::record(
            AuditLogger::PROFILE_UPDATED,
            sprintf('%s updated their profile.', $user->name),
            $user,
            $diff['old'],
            $diff['new'],
        );

        return redirect()
            ->route('profile.edit')
            ->with('success', 'Your profile has been updated.');
    }

    public function editPassword(Request $request): View
    {
        return view('profile.password', [
            'user' => $request->user(),
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => Hash::make($request->validated('password')),
        ])->save();

        AuditLogger::record(
            AuditLogger::PASSWORD_CHANGED,
            sprintf('%s changed their password.', $user->name),
        );

        return redirect()
            ->route('profile.password.edit')
            ->with('success', 'Your password has been changed.');
    }
}
