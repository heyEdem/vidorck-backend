<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderNote;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Facades\DB;

/** Append-only internal note (website-spec §17.1). */
final class AddOrderNote
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(Order $order, StaffUser $actor, string $body): OrderNote
    {
        return DB::transaction(function () use ($order, $actor, $body): OrderNote {
            $note = OrderNote::query()->create(['order_id' => $order->id, 'staff_id' => $actor->id, 'body' => trim($body), 'created_at' => now()]);
            $this->audit->record($actor, 'order.note.added', $order, [], ['orderNumber' => $order->order_number, 'noteId' => $note->id]);

            return $note;
        });
    }
}
