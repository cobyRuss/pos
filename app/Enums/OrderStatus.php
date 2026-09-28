<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Completed = 'completed';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::PartiallyRefunded => 'Partially Refunded',
            self::Refunded => 'Refunded',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Completed => 'text-bg-success',
            self::PartiallyRefunded => 'text-bg-warning',
            self::Refunded => 'text-bg-danger',
            self::Cancelled => 'text-bg-dark',
        };
    }

    /**
     * Cancelled orders are excluded from revenue and best-seller reporting.
     */
    public function countsAsRevenue(): bool
    {
        return $this !== self::Cancelled;
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Completed, self::PartiallyRefunded], true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
