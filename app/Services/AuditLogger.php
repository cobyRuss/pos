<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Records administrative and staff actions in the immutable audit_logs table.
 *
 * Like the prompt logger, this never throws: losing an audit row must not roll
 * back or 500 the business operation that triggered it. Failures are reported
 * through the normal application log instead.
 */
class AuditLogger
{
    public const PRODUCT_CREATED = 'product.created';

    public const PRODUCT_UPDATED = 'product.updated';

    public const PRODUCT_ARCHIVED = 'product.archived';

    public const CATEGORY_CREATED = 'category.created';

    public const CATEGORY_UPDATED = 'category.updated';

    public const CATEGORY_DELETED = 'category.deleted';

    public const STOCK_ADJUSTED = 'inventory.adjusted';

    public const ORDER_COMPLETED = 'order.completed';

    public const ORDER_CANCELLED = 'order.cancelled';

    public const REFUND_PROCESSED = 'refund.processed';

    public const STAFF_CREATED = 'staff.created';

    public const STAFF_UPDATED = 'staff.updated';

    public const SETTINGS_UPDATED = 'settings.updated';

    public function record(
        string $action,
        ?Model $subject = null,
        ?int $userId = null,
        ?string $description = null,
    ): ?AuditLog {
        try {
            return AuditLog::create([
                'user_id' => $userId ?? Auth::id(),
                'action' => $action,
                'model_type' => $subject?->getMorphClass(),
                'model_id' => $subject?->getKey(),
                'description' => $description,
                'ip_address' => Request::ip(),
                'user_agent' => substr((string) Request::userAgent(), 0, 255) ?: null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Audit log write failed.', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
