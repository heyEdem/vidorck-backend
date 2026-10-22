<?php

namespace App\Modules\Staff\Enums;

enum Permission: string
{
    case VIEW_DASHBOARD = 'view-dashboard';
    case VIEW_SALES_FIGURES = 'view-sales-figures';
    case VIEW_ORDERS = 'view-orders';
    case UPDATE_ORDER_STATUS = 'update-order-status';
    case ADD_ORDER_NOTES = 'add-order-notes';
    case CREATE_MANUAL_ORDERS = 'create-manual-orders';
    case EDIT_ORDER_DELIVERY = 'edit-order-delivery';
    case EDIT_ORDER_CONTENTS = 'edit-order-contents';
    case VIEW_REFUNDS = 'view-refunds';
    case ADD_REFUND_NOTES = 'add-refund-notes';
    case DECIDE_REFUNDS = 'decide-refunds';
    case PROCESS_REFUNDS = 'process-refunds';
    case VIEW_CUSTOMERS = 'view-customers';
    case ADD_CUSTOMER_NOTES = 'add-customer-notes';
    case VIEW_CATALOGUE = 'view-catalogue';
    case MANAGE_CATALOGUE = 'manage-catalogue';
    case PUBLISH_CATALOGUE = 'publish-catalogue';
    case MANAGE_IMAGES = 'manage-images';
    case VIEW_COMPLAINTS = 'view-complaints';
    case HANDLE_COMPLAINTS = 'handle-complaints';
    case VIEW_RECONCILIATION = 'view-reconciliation';
    case MANAGE_STAFF = 'manage-staff';
    case VIEW_AUDIT = 'view-audit';
    case CHANGE_OWN_PASSWORD = 'change-own-password';
}
