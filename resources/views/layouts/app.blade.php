<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') &middot; {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased">
<div class="flex min-h-screen">

    @auth
        <aside class="hidden w-64 shrink-0 flex-col border-r border-slate-200 bg-slate-900 text-slate-300 md:flex">
            <div class="flex h-16 items-center gap-2 border-b border-slate-800 px-5">
                <span class="grid h-9 w-9 place-items-center rounded-lg bg-emerald-500 text-lg font-bold text-slate-900">P</span>
                <span class="text-base font-semibold text-white">{{ config('app.name') }}</span>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto p-3 text-sm">
                <a href="{{ route('dashboard') }}"
                   @class([
                       'block rounded-lg px-3 py-2 font-medium transition',
                       'bg-slate-800 text-white' => request()->routeIs('dashboard'),
                       'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('dashboard'),
                   ])>
                    Dashboard
                </a>

                @can('pos.access')
                    <a href="{{ route('pos.index') }}"
                       @class([
                           'block rounded-lg px-3 py-2 font-medium transition',
                           'bg-slate-800 text-white' => request()->routeIs('pos.*'),
                           'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('pos.*'),
                       ])>
                        POS Terminal
                    </a>
                @endcan

                @can('products.view')
                    <a href="{{ route('products.index') }}"
                       @class([
                           'block rounded-lg px-3 py-2 font-medium transition',
                           'bg-slate-800 text-white' => request()->routeIs('products.*'),
                           'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('products.*'),
                       ])>
                        Products
                    </a>
                @endcan

                @can('products.manage')
                    <a href="{{ route('admin.products.index') }}"
                       @class([
                           'block rounded-lg px-3 py-2 font-medium transition',
                           'bg-slate-800 text-white' => request()->routeIs('admin.products.*'),
                           'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('admin.products.*'),
                       ])>
                        Manage Products
                    </a>
                @endcan

                @can('inventory.view')
                    <a href="{{ route('inventory.index') }}"
                       @class([
                           'block rounded-lg px-3 py-2 font-medium transition',
                           'bg-slate-800 text-white' => request()->routeIs('inventory.*', 'admin.inventory.*'),
                           'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('inventory.*', 'admin.inventory.*'),
                       ])>
                        Inventory
                    </a>
                @endcan

                @can('orders.view')
                    <a href="{{ route('orders.index') }}"
                       @class([
                           'block rounded-lg px-3 py-2 font-medium transition',
                           'bg-slate-800 text-white' => request()->routeIs('orders.*'),
                           'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('orders.*'),
                       ])>
                        Orders
                    </a>
                @endcan

                @can('categories.manage')
                    <a href="{{ route('admin.categories.index') }}"
                       @class([
                           'block rounded-lg px-3 py-2 font-medium transition',
                           'bg-slate-800 text-white' => request()->routeIs('admin.categories.*'),
                           'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('admin.categories.*'),
                       ])>
                        Categories
                    </a>
                @endcan

                @can('refunds.process')
                    <a href="{{ route('admin.refunds.index') }}"
                       @class([
                           'block rounded-lg px-3 py-2 font-medium transition',
                           'bg-slate-800 text-white' => request()->routeIs('admin.refunds.*'),
                           'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('admin.refunds.*'),
                       ])>
                        Refunds
                    </a>
                @endcan

                @can('staff.manage')
                    <a href="{{ route('admin.staff.index') }}"
                       @class([
                           'block rounded-lg px-3 py-2 font-medium transition',
                           'bg-slate-800 text-white' => request()->routeIs('admin.staff.*'),
                           'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('admin.staff.*'),
                       ])>
                        Staff
                    </a>
                @endcan

                @can('reports.view')
                    <a href="{{ route('admin.reports.index') }}"
                       @class([
                           'block rounded-lg px-3 py-2 font-medium transition',
                           'bg-slate-800 text-white' => request()->routeIs('admin.reports.*'),
                           'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('admin.reports.*'),
                       ])>
                        Reports
                    </a>
                @endcan

                @can('audit.view')
                    <a href="{{ route('admin.audit-logs.index') }}"
                       @class([
                           'block rounded-lg px-3 py-2 font-medium transition',
                           'bg-slate-800 text-white' => request()->routeIs('admin.audit-logs.*'),
                           'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('admin.audit-logs.*'),
                       ])>
                        Audit Log
                    </a>
                @endcan

                @can('settings.manage')
                    <a href="{{ route('admin.settings.edit') }}"
                       @class([
                           'block rounded-lg px-3 py-2 font-medium transition',
                           'bg-slate-800 text-white' => request()->routeIs('admin.settings.*'),
                           'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('admin.settings.*'),
                       ])>
                        Settings
                    </a>
                @endcan
            </nav>

            <div class="border-t border-slate-800 p-3 text-sm">
                <div class="px-3 py-2">
                    <p class="font-medium text-white">{{ auth()->user()->name }}</p>
                    <p class="text-xs text-slate-400">{{ auth()->user()->email }}</p>
                </div>
                <a href="{{ route('profile.edit') }}" class="block rounded-lg px-3 py-2 hover:bg-slate-800 hover:text-white">
                    My Profile
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full rounded-lg px-3 py-2 text-left hover:bg-slate-800 hover:text-white">
                        Sign out
                    </button>
                </form>
            </div>
        </aside>
    @endauth

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6">
            <h1 class="text-lg font-semibold text-slate-900">@yield('title', 'Dashboard')</h1>

            @auth
                <div class="flex items-center gap-3 text-sm">
                    <span class="hidden rounded-full bg-emerald-100 px-3 py-1 font-medium text-emerald-800 sm:inline">
                        {{ auth()->user()->getRoleNames()->first() ?? 'no role' }}
                    </span>
                    <a href="{{ route('profile.edit') }}" class="font-medium text-slate-600 hover:text-slate-900">
                        {{ auth()->user()->name }}
                    </a>
                </div>
            @endauth
        </header>

        <main class="flex-1 p-4 sm:p-6">
            @include('partials.flash')

            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
