<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Models\RefundNote;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Facades\DB;

/** Append-only internal note on a refund request (both roles). */
final class AddRefundNote
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(RefundRequest $refund, StaffUser $actor, string $body): RefundNote
    {
        return DB::transaction(function () use ($refund, $actor, $body): RefundNote {
            $note = RefundNote::query()->create(['refund_request_id' => $refund->id, 'staff_id' => $actor->id, 'body' => trim($body), 'created_at' => now()]);
            $this->audit->record($actor, 'refund.note.added', $refund, [], ['noteId' => $note->id]);

            return $note;
        });
    }
}
