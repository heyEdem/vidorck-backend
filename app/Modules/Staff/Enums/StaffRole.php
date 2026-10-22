<?php

namespace App\Modules\Staff\Enums;

enum StaffRole: string
{
    case ADMIN = 'ADMIN';
    case SALES_ORDERS = 'SALES_ORDERS';
    case CUSTOMER_SERVICE = 'CUSTOMER_SERVICE';

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::ADMIN => Permission::cases(),
            self::SALES_ORDERS => [
                Permission::VIEW_DASHBOARD,
                Permission::VIEW_ORDERS,
                Permission::UPDATE_ORDER_STATUS,
                Permission::ADD_ORDER_NOTES,
                Permission::CREATE_MANUAL_ORDERS,
                Permission::VIEW_REFUNDS,
                Permission::ADD_REFUND_NOTES,
                Permission::VIEW_CUSTOMERS,
                Permission::ADD_CUSTOMER_NOTES,
                Permission::VIEW_CATALOGUE,
                Permission::CHANGE_OWN_PASSWORD,
            ],
            self::CUSTOMER_SERVICE => [
                Permission::VIEW_DASHBOARD,
                Permission::VIEW_ORDERS,
                Permission::ADD_ORDER_NOTES,
                Permission::VIEW_REFUNDS,
                Permission::ADD_REFUND_NOTES,
                Permission::VIEW_CUSTOMERS,
                Permission::ADD_CUSTOMER_NOTES,
                Permission::VIEW_CATALOGUE,
                Permission::VIEW_COMPLAINTS,
                Permission::HANDLE_COMPLAINTS,
                Permission::CHANGE_OWN_PASSWORD,
            ],
        };
    }

    public function allows(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
