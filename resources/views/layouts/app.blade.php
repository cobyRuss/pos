<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') &middot; {{ config('app.name', 'POS System') }}</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#128722;</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <style>
        /*
         * Critical navbar styles, inlined ahead of app.css on purpose.
         *
         * The sidebar is a light surface with dark ink. If app.css ever fails to
         * load the navigation would fall back to the page's own background, so
         * these rules carry literal fallbacks and paint the surface themselves;
         * when app.css does load it overrides them and supplies the role-scoped
         * theme through the custom properties below.
         *
         * The !important markers are load-bearing, not decoration: the sidebar
         * root is a Bootstrap .offcanvas-lg, and at >=992px Bootstrap ships
         * `.offcanvas-lg { background-color: transparent !important }` plus
         * `.offcanvas-lg .offcanvas-header { display: none }`. Only an equal
         * !important beats those. Every var() fallback below must stay identical
         * to the value app.css declares for the same token - NavbarThemeTest
         * fails if the two copies drift apart.
         */
        body,
        .app-sidebar {
            font-family: "Inter", system-ui, -apple-system, "Segoe UI", Roboto,
                "Helvetica Neue", Arial, sans-serif;
        }
        .app-sidebar {
            background-color: var(--pos-sidebar-bg, #f8fafc) !important;
            color: var(--pos-nav-text, #0f172a);
        }
        .app-sidebar .offcanvas-header {
            display: flex !important;
            background-color: var(--pos-sidebar-bg, #f8fafc) !important;
        }
        .app-sidebar .offcanvas-header a,
        .app-sidebar .text-white,
        .sidebar-profile .profile-name {
            color: var(--pos-sidebar-text, #0f172a) !important;
        }
        .sidebar-nav .sidebar-link {
            color: var(--pos-nav-text, #0f172a) !important;
        }
        .sidebar-nav .sidebar-link i {
            color: var(--pos-nav-muted, #475569);
        }
        .sidebar-nav .sidebar-link:hover {
            color: var(--pos-nav-hover-text, #0f172a) !important;
        }
        .sidebar-nav .sidebar-link:hover i {
            color: var(--pos-nav-hover-text, #0f172a) !important;
        }
        .sidebar-nav .sidebar-link.active,
        .sidebar-nav .sidebar-link.active i {
            color: #fff !important;
        }
        .sidebar-nav .sidebar-link.active {
            background-color: var(--pos-accent, #4f46e5);
        }
        .sidebar-header,
        .sidebar-profile .profile-role {
            color: var(--pos-nav-muted, #475569) !important;
        }
        .avatar,
        .role-badge {
            background-color: var(--pos-accent, #4f46e5) !important;
            color: #fff !important;
        }
    </style>
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">
    @stack('styles')
</head>
<body>
@php
    $currentUser = auth()->user();
    $isAdmin = $currentUser?->isAdmin() ?? false;
    // Drives the role-scoped navbar palette in app.css: the till and the back
    // office are visually distinct so a shared device never causes confusion.
    $roleKey = $isAdmin ? 'admin' : ($currentUser?->isStaff() ? 'staff' : 'guest');
@endphp

<div class="app-shell" data-role="{{ $roleKey }}">
    @include('layouts.partials.sidebar')

    <div class="app-main">
        <header class="app-topbar">
            <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button"
                    data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-label="Toggle navigation">
                <i class="bi bi-list"></i>
            </button>

            <div class="topbar-title">
                <h1 class="h5 mb-0">@yield('page-title', 'Dashboard')</h1>
                @hasSection('page-subtitle')
                    <small class="text-body-secondary">@yield('page-subtitle')</small>
                @endif
            </div>

            <div class="ms-auto d-flex align-items-center gap-2">
                <span class="badge role-badge d-none d-sm-inline">
                    {{ $currentUser?->role->label() }}
                </span>

                <div class="dropdown">
                    <button class="btn btn-sm btn-light dropdown-toggle d-flex align-items-center gap-2"
                            type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar avatar-sm">{{ $currentUser?->initials() }}</span>
                        <span class="d-none d-md-inline">{{ $currentUser?->name }}</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li>
                            <span class="dropdown-item-text small text-body-secondary">
                                {{ $currentUser?->email }}
                            </span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="{{ route('profile.edit') }}"><i class="bi bi-person-gear me-2"></i>Edit Profile</a></li>
                        <li><a class="dropdown-item" href="{{ route('profile.password.edit') }}"><i class="bi bi-key me-2"></i>Change Password</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="app-content">
            @include('layouts.partials.flash')
            @yield('content')
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
@yield('scripts')
</body>
</html>
