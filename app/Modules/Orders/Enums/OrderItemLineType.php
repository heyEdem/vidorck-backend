<?php

namespace App\Modules\Orders\Enums;

enum OrderItemLineType: string
{
    case PRODUCT = 'PRODUCT';
    case BUNDLE = 'BUNDLE';
    case BUNDLE_EXTRA = 'BUNDLE_EXTRA';
}
