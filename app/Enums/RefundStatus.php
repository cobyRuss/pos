<?php

namespace App\Enums;

enum RefundStatus: string
{
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
        };
    }

    public function badgeClass(): string
    {
        return 'text-bg-success';
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
