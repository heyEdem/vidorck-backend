<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Models\Order;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Admin-only delivery correction, audited before/after (website-spec §17.1). Never touches order status. */
final class UpdateOrderDelivery
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, string>  $changes */
    public function execute(Order $order, StaffUser $actor, array $changes, string $reason): Order
    {
        return DB::transaction(function () use ($order, $actor, $changes, $reason): Order {
            $current = StaffUser::query()->find($actor->getKey());
            if ($current === null || ! $current->is_active || $current->role !== StaffRole::ADMIN) {
                throw new AuthorizationException;
            }
            if (isset($changes['area'])) {
                $changes['area'] = app(CanonicalDeliveryArea::class)->resolve($changes['area']);
            }
            $fresh = Order::query()->findOrFail($order->getKey());
            $snapshot = is_array($fresh->delivery_snapshot_json) ? $fresh->delivery_snapshot_json : [];
            $before = array_intersect_key($snapshot, $changes);
            if ($before == $changes) {
                return $fresh;
            }

            $fresh->update(['delivery_snapshot_json' => array_replace($snapshot, $changes)]);
            $this->audit->record($actor, 'order.delivery.updated', $fresh, $before,
                $changes + ['orderNumber' => $fresh->order_number, 'reason' => trim($reason)]);

            return $fresh;
        });
    }
}
