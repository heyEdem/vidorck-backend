<?php

namespace App\Modules\Orders\Enums;

enum RefundTransactionStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSED = 'PROCESSED';
    case FAILED = 'FAILED';
}
