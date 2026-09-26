<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in &middot; {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-screen place-items-center bg-slate-900 px-4">
<div class="w-full max-w-sm">
    <div class="mb-6 text-center">
        <span class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-xl bg-emerald-500 text-2xl font-bold text-slate-900">P</span>
        <h1 class="text-xl font-semibold text-white">{{ config('app.name') }}</h1>
        <p class="text-sm text-slate-400">Sign in to start your shift</p>
    </div>

    <div class="rounded-xl bg-white p-6 shadow-xl">
        @include('partials.flash')

        <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                       autocomplete="username"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-slate-700">Password</label>
                <input id="password" name="password" type="password" required
                       autocomplete="current-password"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">
            </div>

            <label for="remember" class="flex items-center gap-2 text-sm text-slate-600">
                <input id="remember" name="remember" type="checkbox" value="1"
                       class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                Keep me signed in
            </label>

            <button type="submit"
                    class="w-full rounded-lg bg-emerald-500 px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:bg-emerald-400">
                Sign in
            </button>
        </form>
    </div>

    <p class="mt-6 text-center text-xs text-slate-500">
        Accounts are created by an administrator.
    </p>
</div>
</body>
</html>
