<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case StockIn = 'stock_in';
    case StockOut = 'stock_out';
    case Adjustment = 'adjustment';
    case Sale = 'sale';
    case SaleCancellation = 'sale_cancellation';
    case ReturnRestock = 'return_restock';

    public function label(): string
    {
        return match ($this) {
            self::StockIn => 'Stock In',
            self::StockOut => 'Stock Out',
            self::Adjustment => 'Manual Adjustment',
            self::Sale => 'Sold',
            self::SaleCancellation => 'Cancelled Sale',
            self::ReturnRestock => 'Return Restock',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::StockIn, self::ReturnRestock, self::SaleCancellation => 'text-bg-success',
            self::StockOut, self::Sale => 'text-bg-danger',
            self::Adjustment => 'text-bg-warning',
        };
    }

    /**
     * Movements created by the system from a sale/refund rather than by hand.
     */
    public function isSystemGenerated(): bool
    {
        return in_array($this, [self::Sale, self::SaleCancellation, self::ReturnRestock], true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
