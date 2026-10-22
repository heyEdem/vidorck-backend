<?php

namespace App\Modules\Staff\Http\Resources;

use App\Modules\Staff\Models\AuditLog;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/** @mixin AuditLog */
final class AuditLogResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'actor_type' => $this->actor_type, 'actor_id' => $this->actor_id,
            'action' => $this->action, 'target_type' => $this->target_type, 'target_id' => $this->target_id,
            'before' => $this->before, 'after' => $this->after,
            'request_id' => $this->request_id, 'ip_address' => $this->ip_address,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
