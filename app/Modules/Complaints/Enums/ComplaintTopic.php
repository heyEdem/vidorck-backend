<?php

namespace App\Modules\Complaints\Enums;

enum ComplaintTopic: string
{
    case ORDER_PROBLEM = 'ORDER_PROBLEM';
    case PRODUCT_QUESTION = 'PRODUCT_QUESTION';
    case OTHER = 'OTHER';
}
