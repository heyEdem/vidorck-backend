<?php

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNote;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Facades\DB;

/** Append-only internal CRM note; there is no edit or delete path. */
final class AddCustomerNote
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(Customer $customer, StaffUser $actor, string $body): CustomerNote
    {
        return DB::transaction(function () use ($customer, $actor, $body): CustomerNote {
            $note = CustomerNote::query()->create(['customer_id' => $customer->id, 'staff_id' => $actor->id, 'body' => trim($body), 'created_at' => now()]);
            $this->audit->record($actor, 'customer.note.added', $customer, [], ['customerPublicId' => $customer->public_id, 'noteId' => $note->id]);

            return $note;
        });
    }
}
