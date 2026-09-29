@php
    /**
     * Shared navigation. The till is cashier-only and admin-only links are
     * omitted entirely for staff, so neither role ever sees an action it is
     * not permitted to perform.
     */
    $isAdmin = auth()->user()?->isAdmin() ?? false;
    $isStaff = auth()->user()?->isStaff() ?? false;

    $nav = [
        ['route' => 'pos.index', 'label' => 'Point of Sale', 'icon' => 'bi-cash-coin', 'show' => $isStaff],
        ['route' => 'orders.index', 'label' => 'Orders', 'icon' => 'bi-receipt', 'show' => true],
        ['route' => 'products.index', 'label' => 'Products', 'icon' => 'bi-box-seam', 'show' => true],
        ['route' => 'inventory.index', 'label' => 'Inventory', 'icon' => 'bi-boxes', 'show' => true],
        // Shown to everyone: a cashier opens and counts their own, the owner
        // reads everyone's. The counting itself is still staff-only.
        ['route' => 'drawer.index', 'label' => 'Cash Drawer', 'icon' => 'bi-cash-stack', 'show' => true],

        ['header' => 'Administration', 'show' => $isAdmin],
        ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'show' => $isAdmin],
        ['route' => 'admin.users.index', 'label' => 'Staff Accounts', 'icon' => 'bi-people', 'show' => $isAdmin],
        ['route' => 'admin.categories.index', 'label' => 'Categories', 'icon' => 'bi-tags', 'show' => $isAdmin],
        ['route' => 'admin.inventory.movements', 'label' => 'Stock Movements', 'icon' => 'bi-arrow-left-right', 'show' => $isAdmin],
        // The reconciliation behind this list is why the owner, not the cashier,
        // is the one who reads it.
        ['route' => 'admin.refunds.index', 'label' => 'Refunds & Returns', 'icon' => 'bi-arrow-counterclockwise', 'show' => $isAdmin],
        ['route' => 'admin.reports.index', 'label' => 'Reports', 'icon' => 'bi-bar-chart-line', 'show' => $isAdmin],
        ['route' => 'admin.settings.edit', 'label' => 'Settings', 'icon' => 'bi-gear', 'show' => $isAdmin],
        ['route' => 'admin.audit-logs.index', 'label' => 'Audit Logs', 'icon' => 'bi-shield-check', 'show' => $isAdmin],
    ];
@endphp

<div class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="appSidebar" aria-labelledby="appSidebarLabel">
    <div class="offcanvas-header border-bottom border-secondary-subtle">
        <a href="{{ route('home') }}" class="text-decoration-none sidebar-brand">
            <span class="fs-5 fw-semibold">{{ \App\Models\Setting::get('store_name', config('app.name')) }}</span>
        </a>
        <button type="button" class="btn-close d-lg-none"
                data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body flex-column p-0">
        {{-- Signed-in user, above the navigation: the till and the back office
             are separate roles, so who is at the keyboard should be obvious. --}}
        <div class="sidebar-profile">
            <span class="avatar">{{ auth()->user()?->initials() }}</span>
            <div class="min-w-0">
                <div class="profile-name text-truncate">{{ auth()->user()?->name }}</div>
                <div class="profile-role text-truncate">{{ auth()->user()?->role->label() }}</div>
            </div>
        </div>

        <nav class="nav flex-column sidebar-nav py-2">
            @foreach ($nav as $item)
                @continue(! $item['show'])

                @if (! empty($item['header']))
                    <div class="sidebar-header">{{ $item['header'] }}</div>
                @else
                    @php $active = request()->routeIs($item['route']) || request()->routeIs($item['route'].'.*'); @endphp
                    <a href="{{ route($item['route']) }}"
                       class="sidebar-link {{ $active ? 'active' : '' }}">
                        <i class="bi {{ $item['icon'] }}"></i>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endif
            @endforeach
        </nav>
    </div>
</div>
