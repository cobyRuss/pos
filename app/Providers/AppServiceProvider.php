<?php

namespace App\Providers;

use App\Events\DayClosed;
use App\Events\RefundProcessed;
use App\Listeners\SendDaySummary;
use App\Listeners\SendRefundAlert;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // This app is styled with Bootstrap 5, but Laravel's default paginator
        // ships Tailwind markup, which renders as unstyled text here. Point the
        // paginator at the Bootstrap views the framework already provides so all
        // listings get working controls without passing links() a view name.
        Paginator::defaultView('pagination::bootstrap-5');
        Paginator::defaultSimpleView('pagination::simple-bootstrap-5');

        // Registered explicitly rather than left to auto-discovery, because the
        // whole alerting story depends on this mapping existing: if it ever goes
        // missing, refunds still complete but the owner is never told.
        Event::listen(RefundProcessed::class, SendRefundAlert::class);
        Event::listen(DayClosed::class, SendDaySummary::class);
    }
}
