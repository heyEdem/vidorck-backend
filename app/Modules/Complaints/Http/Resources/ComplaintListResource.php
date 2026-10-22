<?php

namespace App\Modules\Complaints\Http\Resources;

use App\Modules\Complaints\Models\Complaint;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/** @property Complaint $resource */
final class ComplaintListResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return ['reference' => $c->reference, 'topic' => $c->topic->value, 'name' => $c->name,
            'orderNumberEntered' => $c->order_number_entered, 'status' => $c->status->value,
            'assignedStaff' => $c->assignedStaff ? ['id' => $c->assignedStaff->id, 'name' => $c->assignedStaff->name] : null,
            'createdAt' => $c->created_at?->toIso8601String(), 'updatedAt' => $c->updated_at?->toIso8601String()];
    }
}
