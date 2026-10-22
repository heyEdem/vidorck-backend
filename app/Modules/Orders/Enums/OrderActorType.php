<?php

namespace App\Modules\Orders\Enums;

enum OrderActorType: string
{
    case SYSTEM = 'SYSTEM';
    case STAFF = 'STAFF';
}
