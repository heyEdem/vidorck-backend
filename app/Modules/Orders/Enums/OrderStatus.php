<?php

namespace App\Modules\Orders\Enums;

enum OrderStatus: string
{
    case PAID = 'PAID';
    case PROCESSING = 'PROCESSING';
    case READY_FOR_DISPATCH = 'READY_FOR_DISPATCH';
    case DISPATCHED = 'DISPATCHED';
    case DELIVERED = 'DELIVERED';
    case CANCELLED = 'CANCELLED';
    case REFUNDED = 'REFUNDED';

    /** Customers may request cancellation only before dispatch (website-spec §16.1). */
    public function acceptsCancellationRequest(): bool
    {
        return in_array($this, [self::PAID, self::PROCESSING, self::READY_FOR_DISPATCH], true);
    }
}
