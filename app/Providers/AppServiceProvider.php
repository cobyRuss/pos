<?php

namespace App\Providers;

use App\Enums\Role;
use App\Services\RolePermissionSyncer;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RolePermissionSyncer::class);
    }

    public function boot(): void
    {
        // Admins bypass every permission check; staff are governed by the
        // permission set assigned to their role.
        Gate::before(fn ($user) => $user->hasRole(Role::Admin) ? true : null);

        // Roles/permissions are written by the migrator rather than on every
        // request, so a fresh install and a fresh test database both end up
        // with a populated roles table without any per-request writes.
        Event::listen(MigrationsEnded::class, function (MigrationsEnded $event) {
            if ($event->method === 'up') {
                $this->app->make(RolePermissionSyncer::class)->sync();
            }
        });
    }
}
