<?php

namespace App\Modules\Orders\Enums;

/** How an order edit's price difference was settled outside Paystack. */
enum SettlementMethod: string
{
    case NONE = 'NONE';
    case CASH = 'CASH';
    case MOMO = 'MOMO';
    case BANK = 'BANK';
    case OTHER = 'OTHER';
}
