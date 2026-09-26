<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\LotController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RefundController;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\RoleMiddleware;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::post('profile/confirm-password', [ProfileController::class, 'confirmPassword'])->name('profile.password.confirm');

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Product discovery: staff and admins.
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');

    // Stock balances are readable by staff; adjusting them is admin-only and
    // enforced by the inventory.adjust permission on the write routes.
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('inventory/logs', [InventoryController::class, 'logs'])
        ->middleware('permission:'.Permission::InventoryAdjust->value)
        ->name('inventory.logs');

    // POS terminal. The cart lives in the session, so these are ordinary
    // form posts rather than JSON endpoints.
    Route::middleware('permission:'.Permission::PosAccess->value)->prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [PosController::class, 'index'])->name('index');
        Route::post('cart', [PosController::class, 'store'])->name('cart.store');
        Route::patch('cart/{product}', [PosController::class, 'update'])->name('cart.update');
        Route::delete('cart/{product}', [PosController::class, 'destroy'])->name('cart.destroy');
        Route::post('cart/discount', [PosController::class, 'discount'])->name('cart.discount');
        Route::delete('cart', [PosController::class, 'clear'])->name('cart.clear');

        Route::post('checkout', [PosController::class, 'checkout'])
            ->middleware('permission:'.Permission::PosCreateOrder->value)
            ->name('checkout');

        Route::get('orders/{order}/receipt', [PosController::class, 'receipt'])->name('receipt');
    });

    // Sales history. Visibility of individual orders is re-checked in the
    // controller: orders.view alone only covers the staff member's own till.
    Route::middleware('permission:'.Permission::OrderView->value)->prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('{order}', [OrderController::class, 'show'])->name('show');

        Route::post('{order}/cancel', [OrderController::class, 'cancel'])
            ->middleware('permission:'.Permission::OrderCancel->value)
            ->name('cancel');
    });
});

// Admin-only area. The role check is enforced here and mirrored in the layout
// with @can directives so restricted links are never rendered.
Route::middleware(['auth', RoleMiddleware::using(Role::Admin)])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::resource('products', AdminProductController::class)->except('show');
        Route::resource('categories', CategoryController::class)->except('show');

        Route::get('inventory/{product}/adjust', [InventoryController::class, 'create'])
            ->name('inventory.adjust.create');
        Route::post('inventory/{product}/adjust', [InventoryController::class, 'store'])
            ->name('inventory.adjust.store');

        // Batches for products that track expiry. Receiving a delivery and
        // counting a batch are the same routes the plain stock forms would
        // use, except the batch is named so the movement reconciles per batch.
        Route::get('products/{product}/lots', [LotController::class, 'index'])->name('lots.index');
        Route::get('products/{product}/lots/create', [LotController::class, 'create'])->name('lots.create');
        Route::post('products/{product}/lots', [LotController::class, 'store'])->name('lots.store');
        Route::get('products/{product}/lots/{lot}/edit', [LotController::class, 'edit'])->name('lots.edit');
        Route::put('products/{product}/lots/{lot}', [LotController::class, 'update'])->name('lots.update');
        Route::delete('products/{product}/lots/{lot}', [LotController::class, 'destroy'])->name('lots.destroy');

        // Returns and refunds. refunds.process is admin-only, so the refund
        // routes sit inside the admin area.
        Route::get('refunds', [RefundController::class, 'index'])->name('refunds.index');
        Route::get('refunds/{refund}', [RefundController::class, 'show'])->name('refunds.show');
        Route::post('orders/{order}/refund', [RefundController::class, 'store'])
            ->name('refunds.store');
        Route::post('orders/{order}/refund-all', [RefundController::class, 'storeAll'])
            ->name('refunds.all');

        // Staff accounts. Deactivation, not deletion, preserves till history.
        // The parameter is renamed to {user} so implicit binding resolves it
        // to the User model the controller type-hints.
        Route::resource('staff', StaffController::class)
            ->except('show')
            ->parameters(['staff' => 'user']);
        Route::delete('staff/{user}/purge', [StaffController::class, 'destroyPermanently'])
            ->name('staff.purge');

        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
        // Printable table view: the plan's fallback where no PDF package exists.
        Route::get('reports/print/{report?}', [ReportController::class, 'print'])
            ->whereIn('report', ['sales', 'inventory'])
            ->name('reports.print');

        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    });
