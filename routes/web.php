<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DrawerController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest routes
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Authenticated routes - shared by admin and staff
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    // Root sends each role to its own home screen.
    Route::get('/', function (Request $request) {
        return $request->user()->isAdmin()
            ? redirect()->route('admin.dashboard')
            : redirect()->route('pos.index');
    })->name('home');

    // Profile
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('profile/password', [ProfileController::class, 'editPassword'])->name('profile.password.edit');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    /*
    |----------------------------------------------------------------------
    | Point of sale - cashiers only
    |----------------------------------------------------------------------
    | Selling is a cashier responsibility: an administrator who can ring up
    | sales can also edit the products, prices and stock behind them, which
    | makes the cashier attribution on reports meaningless. Admins keep
    | full oversight through orders, inventory and the reports instead.
    */
    Route::middleware('role:staff')->prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [PosController::class, 'index'])->name('index');
        Route::get('search', [PosController::class, 'search'])->name('search');
        Route::get('lookup', [PosController::class, 'lookup'])->name('lookup');
        Route::post('cart', [PosController::class, 'store'])->name('cart.store');
        Route::patch('cart/{product}', [PosController::class, 'update'])->name('cart.update');
        Route::delete('cart/{product}', [PosController::class, 'destroy'])->name('cart.destroy');
        Route::delete('cart', [PosController::class, 'clear'])->name('cart.clear');
        Route::post('checkout', [PosController::class, 'checkout'])->name('checkout');

        // The drawer is a till action: only a cashier holds the cash, so only a
        // cashier opens and counts it. The owner reads everyone's drawers from
        // the same screen via the shared GET below.
        Route::post('drawer/open', [DrawerController::class, 'open'])->name('drawer.open');
        Route::post('drawer/{cashSession}/close', [DrawerController::class, 'close'])->name('drawer.close');
    });

    /*
    |----------------------------------------------------------------------
    | Cash drawer
    |   Shared because the owner has to be able to read every cashier's
    |   counted drawer, even though they never open one themselves.
    |----------------------------------------------------------------------
    */
    Route::get('drawer', [DrawerController::class, 'index'])->name('drawer.index');

    /*
    |----------------------------------------------------------------------
    | Orders & sales history
    |   Staff are scoped to their own orders inside the controller.
    |----------------------------------------------------------------------
    */
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('orders/{order}/receipt', [OrderController::class, 'receipt'])->name('orders.receipt');
    Route::put('orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');

    /*
    |----------------------------------------------------------------------
    | Catalog browsing - read-only for staff, CRUD for admin
    |----------------------------------------------------------------------
    */
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');

    // Inventory levels are read-only for everyone; the adjustment log and
    // stock edits below are admin-only.
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');

    /*
    |----------------------------------------------------------------------
    | Refunds - cashiers and the owner
    |----------------------------------------------------------------------
    | The administrator is never on the till, so refusing refunds to staff
    | would mean refusing them altogether: a customer waiting at the counter
    | while someone is phoned. The guardrails are therefore not "who may press
    | the button" but "what the button is allowed to do" - the amount is derived
    | from the purchased lines, the same receipt cannot be spent twice, every
    | refund is bound to the cashier's own account, and a daily ceiling stops a
    | slow drip. See RefundService for the rest.
    |
    | The listing and the review action stay admin-only: the reconciliation is
    | the owner's job, not the cashier's.
    */
    Route::get('orders/{order}/refund', [RefundController::class, 'create'])->name('refunds.create');
    Route::post('orders/{order}/refund', [RefundController::class, 'store'])->name('refunds.store');
    Route::get('refunds/{refund}', [RefundController::class, 'show'])->name('refunds.show');
});

/*
|--------------------------------------------------------------------------
| Admin-only routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Dashboard
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Staff / user management
    Route::resource('users', UserController::class)->except(['show']);

    // Products (admin gets full CRUD on top of the shared read routes)
    Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('products', [ProductController::class, 'store'])->name('products.store');
    Route::get('products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

    // Categories
    Route::resource('categories', CategoryController::class)->except(['show']);

    // Inventory management
    Route::get('inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
    Route::get('products/{product}/stock', [InventoryController::class, 'create'])->name('inventory.create');
    Route::post('products/{product}/stock', [InventoryController::class, 'store'])->name('inventory.store');

    // Delivery lots and their expiry dates
    Route::get('products/{product}/batches', [BatchController::class, 'index'])->name('batches.index');
    Route::post('products/{product}/batches', [BatchController::class, 'store'])->name('batches.store');
    Route::put('batches/{batch}', [BatchController::class, 'update'])->name('batches.update');
    Route::delete('batches/{batch}', [BatchController::class, 'destroy'])->name('batches.destroy');

    // Refunds / returns - the owner's oversight, not the cashier's
    Route::get('refunds', [RefundController::class, 'index'])->name('refunds.index');
    Route::post('refunds/{refund}/review', [RefundController::class, 'review'])->name('refunds.review');

    // Reports
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
    Route::get('reports/revenue', [ReportController::class, 'revenue'])->name('reports.revenue');
    Route::get('reports/inventory', [ReportController::class, 'inventory'])->name('reports.inventory');
    Route::get('reports/refunds', [ReportController::class, 'refunds'])->name('reports.refunds');

    // Settings
    Route::get('settings', [SettingController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingController::class, 'update'])->name('settings.update');

    // Audit logs
    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');
});
