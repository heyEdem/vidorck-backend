<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Enums\OrderActorType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusEvent;

final class RecordOrderStatusEvent
{
    public function record(
        Order $order,
        ?OrderStatus $fromStatus,
        OrderStatus $toStatus,
        OrderActorType $actorType,
        ?int $actorId = null,
        ?string $reason = null,
    ): OrderStatusEvent {
        return OrderStatusEvent::query()->create([
            'order_id' => $order->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
