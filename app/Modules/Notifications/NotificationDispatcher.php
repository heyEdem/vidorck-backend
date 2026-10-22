<?php

namespace App\Modules\Notifications;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Jobs\SendNotification;
use App\Modules\Notifications\Repositories\NotificationLogRepository;
use App\Modules\Notifications\Support\NotificationPayload;
use App\Modules\Notifications\Support\RecipientIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Safe to call inside a caller's DB transaction: the log row and the job row are written on the same
 * default SQLite connection, so both commit or roll back with the caller. No provider call happens here.
 */
final class NotificationDispatcher
{
    public function __construct(private readonly NotificationLogRepository $logs) {}

    /** Builds a per-occurrence, per-channel, per-recipient key. */
    public static function key(string $occurrence, NotificationChannel $channel, string $recipient): string
    {
        return $occurrence.':'.$channel->value.':'.RecipientIdentity::for($channel, $recipient)->hash;
    }

    /** @return bool true when queued, false when the key was already used */
    public function once(string $key, NotificationChannel $channel, string $recipient, NotificationPayload $payload): bool
    {
        $identity = RecipientIdentity::for($channel, $recipient);
        try {
            // Own (nested: savepoint) transaction so a failed push also removes the log row and the key is not burned.
            DB::transaction(function () use ($key, $channel, $payload, $identity): void {
                $log = $this->logs->insert([
                    'idempotency_key' => $key,
                    'channel' => $channel,
                    'template' => $payload->template,
                    'recipient_masked' => $identity->masked,
                    'recipient_hash' => $identity->hash,
                    'source_type' => $payload->sourceType,
                    'source_id' => $payload->sourceId === null ? null : (string) $payload->sourceId,
                ]);
                $job = new SendNotification($log->id, $channel, $identity->normalised, $payload);
                $job->afterCommit = false;
                Queue::connection('database')->push($job);
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'idempotency_key')) {
                throw $e;
            }

            return false;
        }

        return true;
    }
}
