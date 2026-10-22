<?php

namespace App\Modules\Complaints\Http\Resources;

use App\Modules\Complaints\Models\Complaint;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/** @property Complaint $resource */
final class ComplaintDetailResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return ['reference' => $c->reference, 'name' => $c->name, 'topic' => $c->topic->value, 'message' => $c->message,
            'contactMethod' => $c->contact_method->value, 'phone' => $c->phone_e164, 'email' => $c->email,
            'orderNumberEntered' => $c->order_number_entered, 'status' => $c->status->value,
            'assignedStaff' => $c->assignedStaff ? ['id' => $c->assignedStaff->id, 'name' => $c->assignedStaff->name] : null,
            'createdAt' => $c->created_at?->toIso8601String(), 'updatedAt' => $c->updated_at?->toIso8601String(),
            'resolvedAt' => $c->resolved_at?->toIso8601String(),
            'customer' => $c->customer ? ['publicId' => $c->customer->public_id, 'fullName' => $c->customer->full_name] : null,
            'order' => $c->order ? ['orderNumber' => $c->order->order_number, 'status' => $c->order->status->value] : null,
            'events' => $c->events->map(static fn ($e): array => ['id' => $e->id, 'type' => $e->type->value,
                'fromStatus' => $e->from_status?->value, 'toStatus' => $e->to_status?->value, 'body' => $e->body,
                'staffId' => $e->staff_id, 'staffName' => $e->staff?->name, 'createdAt' => $e->created_at->toIso8601String()])->all()];
    }
}
