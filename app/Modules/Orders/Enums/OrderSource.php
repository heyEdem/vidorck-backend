<?php

namespace App\Modules\Orders\Enums;

enum OrderSource: string
{
    case ONLINE = 'ONLINE';
    case MANUAL = 'MANUAL';
}
