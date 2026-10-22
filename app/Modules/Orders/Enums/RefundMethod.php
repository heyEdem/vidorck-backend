<?php

namespace App\Modules\Orders\Enums;

enum RefundMethod: string
{
    case PAYSTACK = 'PAYSTACK';
    case MANUAL = 'MANUAL';
}
