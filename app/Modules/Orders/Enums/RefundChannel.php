<?php

namespace App\Modules\Orders\Enums;

enum RefundChannel: string
{
    case WEB = 'WEB';
    case WHATSAPP = 'WHATSAPP';
    case STAFF = 'STAFF';
}
