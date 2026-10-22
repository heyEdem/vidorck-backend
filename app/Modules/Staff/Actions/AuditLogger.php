<?php

namespace App\Modules\Staff\Actions;

use App\Modules\Staff\Models\AuditLog;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(?StaffUser $actor, string $action, ?Model $target = null, array $before = [], array $after = []): AuditLog
    {
        return AuditLog::create([
            'actor_type' => $actor ? 'STAFF' : 'SYSTEM',
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target ? (string) $target->getKey() : null,
            'before' => $this->redact($before),
            'after' => $this->redact($after),
            'request_id' => request()->attributes->get('request_id') ?: (string) Str::uuid(),
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }

    /** @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data): array
    {
        $safe = [];
        foreach ($data as $key => $value) {
            $normalized = is_string($key) ? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $key) ?? '') : '';
            if (preg_match('/password|token|secret|cookie|authorization|key|card|cvv|cvc|payment|payload|^pan$|^pin$/', $normalized)) {
                continue;
            }
            // Non-JSON objects can hide credentials from recursive inspection.
            $safe[$key] = is_array($value) ? $this->redact($value)
                : (is_scalar($value) || $value === null ? $value : '[redacted]');
        }

        return $safe;
    }
}
