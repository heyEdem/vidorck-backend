<?php

namespace App\Modules\Notifications\Enums;

enum NotificationChannel: string
{
    case EMAIL = 'EMAIL';
    case SMS = 'SMS';
}
