<?php

namespace App\Modules\Complaints\Enums;

enum ComplaintContactMethod: string
{
    case CALL = 'CALL';
    case WHATSAPP = 'WHATSAPP';
    case EMAIL = 'EMAIL';
}
