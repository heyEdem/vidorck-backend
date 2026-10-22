<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\Enums\NotificationAttemptEvent as Event;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Exceptions\PermanentNotificationException;
use App\Modules\Notifications\Repositories\NotificationAttemptRepository;
use App\Modules\Notifications\Support\NotificationDeliveryPolicy;
use App\Modules\Notifications\Support\NotificationPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One delivery per recipient. The raw recipient only lives in this encrypted job payload.
 * A STARTED attempt row is the claim (unique log+number+event); the latest attempt is the status.
 */
final class SendNotification implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 30;

    /** A STARTED claim younger than this is considered in flight (kept below the database queue retry_after of 90s, so a redelivered job after a crash can take the claim over). */
    public const IN_FLIGHT_SECONDS = 60;

    public function __construct(
        public readonly int $logId,
        public readonly NotificationChannel $channel,
        public readonly string $recipient,
        public readonly NotificationPayload $payload,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(NotificationAttemptRepository $attempts, NotificationDeliveryPolicy $policy): void
    {
        $latest = $attempts->latest($this->logId);
        if ($latest?->event_type->isTerminal()) {
            return;
        }
        if ($latest?->event_type === Event::STARTED && $latest->created_at->gt(now()->subSeconds(self::IN_FLIGHT_SECONDS))) {
            $this->release(self::IN_FLIGHT_SECONDS + 5); // Another worker holds the claim: retry later, never drop the job.

            return;
        }
        $number = ($latest === null ? 0 : $latest->attempt_number) + 1;
        if ($attempts->append($this->logId, $number, Event::STARTED) === null) {
            $this->release(self::IN_FLIGHT_SECONDS + 5); // Lost the claim race: the winner may crash, so retry rather than drop.

            return;
        }

        if (! $policy->allows($this->channel, $this->recipient)) {
            $attempts->append($this->logId, $number, Event::SKIPPED, errorCode: 'NOT_ALLOWLISTED', errorSummary: 'Recipient is not on the non-production allowlist.');

            return;
        }

        try {
            $result = $this->sender()->send($this->recipient, $this->payload);
        } catch (PermanentNotificationException $e) {
            $attempts->append($this->logId, $number, Event::FAILED, errorCode: class_basename($e), errorSummary: $e->getMessage());
            $attempts->append($this->logId, $number, Event::EXHAUSTED, errorCode: 'PERMANENT');
            $this->fail($e);

            return;
        } catch (\Throwable $e) {
            $attempts->append($this->logId, $number, Event::FAILED, errorCode: class_basename($e), errorSummary: $e->getMessage());
            throw $e;
        }
        $attempts->append($this->logId, $number, Event::SENT, $result->provider, $result->messageId);
    }

    /** Called by the worker once tries are exhausted (or after a permanent failure); idempotent. */
    public function failed(?\Throwable $e = null): void
    {
        $attempts = app(NotificationAttemptRepository::class);
        $latest = $attempts->latest($this->logId);
        if ($latest?->event_type->isTerminal()) {
            return;
        }
        $attempts->append($this->logId, $latest === null ? 1 : $latest->attempt_number, Event::EXHAUSTED, errorCode: $e === null ? null : class_basename($e), errorSummary: $e?->getMessage());
    }

    private function sender(): NotificationSender
    {
        foreach (app()->tagged('notification.senders') as $sender) {
            if ($sender instanceof NotificationSender && $sender->channel() === $this->channel) {
                return $sender;
            }
        }
        throw new \LogicException('No notification sender registered for '.$this->channel->value.'.');
    }
}
