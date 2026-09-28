<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Records key admin/staff actions in the audit_logs table (who did what, when).
 */
class AuditLogger
{
    public const LOGIN = 'login';

    public const LOGOUT = 'logout';

    public const FAILED_LOGIN = 'failed_login';

    public const PROFILE_UPDATED = 'profile.updated';

    public const PASSWORD_CHANGED = 'password.changed';

    public const ORDER_CREATED = 'order.created';

    public const ORDER_CANCELLED = 'order.cancelled';

    public const REFUND_CREATED = 'refund.created';

    public const REFUND_ALERT_FAILED = 'refund.alert_failed';

    public const REFUND_REVIEWED = 'refund.reviewed';

    public const REFUND_DAILY_LIMIT_BLOCKED = 'refund.daily_limit_blocked';

    public const PRODUCT_CREATED = 'product.created';

    public const PRODUCT_UPDATED = 'product.updated';

    public const PRODUCT_DELETED = 'product.deleted';

    public const CATEGORY_CREATED = 'category.created';

    public const CATEGORY_UPDATED = 'category.updated';

    public const CATEGORY_DELETED = 'category.deleted';

    public const STOCK_ADJUSTED = 'inventory.adjusted';

    public const USER_CREATED = 'user.created';

    public const USER_UPDATED = 'user.updated';

    public const USER_DELETED = 'user.deleted';

    public const SETTINGS_UPDATED = 'settings.updated';

    public const ACCESS_DENIED = 'access.denied';

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public static function record(
        string $action,
        string $description,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        try {
            $user = Auth::user();

            AuditLog::create([
                'user_id' => $user?->getAuthIdentifier(),
                'user_name' => $user?->name,
                'action' => $action,
                'description' => $description,
                'auditable_type' => $auditable?->getMorphClass(),
                'auditable_id' => $auditable?->getKey(),
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => Request::ip(),
                'user_agent' => substr((string) Request::userAgent(), 0, 500) ?: null,
            ]);
        } catch (Throwable $e) {
            // Auditing must never break the request.
            report($e);
        }
    }

    /**
     * Diff two attribute sets, dropping password hashes and unchanged keys.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public static function diff(array $old, array $new): array
    {
        $redact = ['password', 'password_confirmation', 'remember_token'];

        $oldFiltered = array_intersect_key($old, array_flip($redact)) ? [] : $old;
        $newFiltered = $new;

        foreach ($redact as $key) {
            unset($oldFiltered[$key], $newFiltered[$key]);
        }

        $changedOld = [];
        $changedNew = [];

        foreach ($newFiltered as $key => $value) {
            if (! array_key_exists($key, $oldFiltered) || $oldFiltered[$key] !== $value) {
                $changedOld[$key] = $oldFiltered[$key] ?? null;
                $changedNew[$key] = $value;
            }
        }

        return ['old' => $changedOld, 'new' => $changedNew];
    }
}
