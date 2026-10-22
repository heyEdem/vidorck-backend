<?php

namespace App\Modules\Checkout\Enums;

enum CheckoutAttemptStatus: string
{
    case PENDING = 'PENDING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
    case ABANDONED = 'ABANDONED';
    case MISMATCH = 'MISMATCH';
}
