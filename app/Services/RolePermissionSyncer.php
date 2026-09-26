<?php

namespace App\Services;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

class RolePermissionSyncer
{
    /**
     * Idempotently reconcile the roles/permissions tables with the enums, so
     * the database always matches code. Safe to run on every deploy.
     */
    public function sync(): void
    {
        if (! Schema::hasTable(config('permission.table_names.roles'))) {
            return;
        }

        $this->flushPermissionCache();

        foreach (Permission::cases() as $permission) {
            SpatiePermission::findOrCreate($permission->value, 'web');
        }

        foreach (Role::cases() as $role) {
            $granted = match ($role) {
                Role::Admin => Permission::forAdmin(),
                Role::Staff => Permission::forStaff(),
            };

            SpatieRole::findOrCreate($role->value, 'web')
                ->syncPermissions(array_map(fn (Permission $p) => $p->value, $granted));
        }
    }

    protected function flushPermissionCache(): void
    {
        $store = config('permission.cache.store');

        app('cache')->store($store !== 'default' ? $store : null)->forget(config('permission.cache.key'));
    }
}
