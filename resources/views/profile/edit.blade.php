@extends('layouts.app')

@section('title', 'My Profile')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">

        <form method="POST" action="{{ route('profile.update') }}"
              class="rounded-xl border border-slate-200 bg-white p-6">
            @csrf
            @method('PATCH')

            <h2 class="text-base font-semibold text-slate-900">Profile</h2>
            <p class="mt-1 text-sm text-slate-500">Your name and contact details.</p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="name" class="mb-1 block text-sm font-medium text-slate-700">Name</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="phone" class="mb-1 block text-sm font-medium text-slate-700">Phone</label>
                    <input id="phone" name="phone" type="text" value="{{ old('phone', $user->phone) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <span class="mb-1 block text-sm font-medium text-slate-700">Roles</span>
                    <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        {{ $user->getRoleNames()->join(', ') ?: 'Unassigned' }}
                    </p>
                </div>
            </div>

            <div class="mt-4 flex justify-end">
                <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                    Save profile
                </button>
            </div>
        </form>

        <form method="POST" action="{{ route('profile.password.update') }}"
              class="rounded-xl border border-slate-200 bg-white p-6">
            @csrf
            @method('PUT')

            <h2 class="text-base font-semibold text-slate-900">Change password</h2>
            <p class="mt-1 text-sm text-slate-500">
                Changing your password signs out every other device immediately.
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="current_password" class="mb-1 block text-sm font-medium text-slate-700">Current password</label>
                    <input id="current_password" name="current_password" type="password" required
                           autocomplete="current-password"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="password" class="mb-1 block text-sm font-medium text-slate-700">New password</label>
                    <input id="password" name="password" type="password" required autocomplete="new-password"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="password_confirmation" class="mb-1 block text-sm font-medium text-slate-700">Confirm new password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required
                           autocomplete="new-password"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>

            <div class="mt-4 flex justify-end">
                <button type="submit" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500">
                    Change password
                </button>
            </div>
        </form>
    </div>
@endsection
