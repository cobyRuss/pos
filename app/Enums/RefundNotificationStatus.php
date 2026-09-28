<?php

namespace App\Enums;

/**
 * Delivery state of an out-of-band refund alert.
 *
 * Tracked rather than fire-and-forget: a refund that completed but whose alert
 * silently failed is exactly the case the owner needs to know about, so the
 * failure has to be visible somewhere an admin will actually look.
 */
enum RefundNotificationStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'text-bg-secondary',
            self::Sent => 'text-bg-success',
            self::Failed => 'text-bg-danger',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
