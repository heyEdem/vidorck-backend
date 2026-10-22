<?php

namespace App\Modules\Complaints\Enums;

enum ComplaintEventType: string
{
    case SUBMITTED = 'SUBMITTED';
    case CLAIMED = 'CLAIMED';
    case NOTE = 'NOTE';
    case STATUS_CHANGED = 'STATUS_CHANGED';
}
