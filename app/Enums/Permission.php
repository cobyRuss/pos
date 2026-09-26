<?php

namespace App\Enums;

enum Permission: string
{
    case PosAccess = 'pos.access';
    case PosCreateOrder = 'pos.create-order';

    case ProductView = 'products.view';
    case ProductManage = 'products.manage';
    case CategoryManage = 'categories.manage';

    case InventoryView = 'inventory.view';
    case InventoryAdjust = 'inventory.adjust';

    // Lets a manager ring up past-date stock for a markdown or a write-off.
    // Cashiers cannot, so expired goods cannot leave the building by accident.
    case SellExpiredStock = 'inventory.sell-expired';

    case OrderView = 'orders.view';
    case OrderViewAll = 'orders.view-all';
    case OrderCancel = 'orders.cancel';

    case RefundProcess = 'refunds.process';

    case StaffManage = 'staff.manage';
    case ReportView = 'reports.view';
    case SettingsManage = 'settings.manage';
    case AuditView = 'audit.view';

    public function label(): string
    {
        return match ($this) {
            self::PosAccess => 'Access POS terminal',
            self::PosCreateOrder => 'Process checkout',
            self::ProductView => 'View products',
            self::ProductManage => 'Manage products',
            self::CategoryManage => 'Manage categories',
            self::InventoryView => 'View inventory & low stock',
            self::InventoryAdjust => 'Adjust stock & write-offs',
            self::SellExpiredStock => 'Sell expired stock',
            self::OrderView => 'View own orders',
            self::OrderViewAll => 'View all orders',
            self::OrderCancel => 'Cancel orders',
            self::RefundProcess => 'Process refunds & returns',
            self::StaffManage => 'Manage staff accounts',
            self::ReportView => 'View reports',
            self::SettingsManage => 'Manage system settings',
            self::AuditView => 'View audit log',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Permissions granted to the staff role.
     *
     * @return array<int, self>
     */
    public static function forStaff(): array
    {
        return [
            self::PosAccess,
            self::PosCreateOrder,
            self::ProductView,
            self::InventoryView,
            self::OrderView,
        ];
    }

    /**
     * Permissions granted to the admin role. Admins also bypass every check
     * via Gate::before(), but they are still assigned explicitly so the
     * role's permission set stays introspectable.
     *
     * @return array<int, self>
     */
    public static function forAdmin(): array
    {
        return self::cases();
    }
}
