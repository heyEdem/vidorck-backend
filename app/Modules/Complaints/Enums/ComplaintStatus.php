<?php

namespace App\Modules\Complaints\Enums;

enum ComplaintStatus: string
{
    case NEW = 'NEW';
    case IN_PROGRESS = 'IN_PROGRESS';
    case RESOLVED = 'RESOLVED';
}
