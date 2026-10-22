<?php

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Exceptions\PermanentNotificationException;
use App\Modules\Notifications\Exceptions\TransientNotificationException;
use App\Modules\Notifications\Sms\SmsOnlineGh\SmsOnlineGhClient;
use App\Modules\Notifications\Sms\SmsOnlineGh\SmsOnlineGhOutcome;

/** SMSOnlineGH via the vendored Zenoph SDK. Errors carry codes only; provider text is never echoed. */
final class SmsOnlineGhSmsProvider implements SmsProvider
{
    private const PERMANENT_HTTP = [400, 401, 403, 422];

    private const PERMANENT_HANDSHAKE = [1203, 1204, 1205, 1401, 1402, 1405, 1408, 1409];

    private const PERMANENT_STATUS = [2119, 2121, 2126, 2127, 2128, 2140];

    /** Queued, submitted, delivered and similar: the provider took the message. */
    private const ACCEPTED_STATUS = [2104, 2105, 2106, 2107, 2108, 2109, 2110, 2139];

    public function __construct(private readonly SmsOnlineGhClient $client) {}

    public function send(string $toE164, string $message): SmsResult
    {
        $key = (string) config('services.smsonlinegh.key');
        if ($key === '') {
            throw new TransientNotificationException('SMSOnlineGH API key is not configured.');
        }

        $to = ltrim($toE164, '+');
        if (preg_match('/^[0-9]{8,13}$/', $to) !== 1) {
            throw new PermanentNotificationException('SMSOnlineGH rejected the recipient number.');
        }

        $outcome = $this->client->send(
            (string) config('services.smsonlinegh.host'),
            $key,
            (string) config('services.smsonlinegh.sender_id'),
            $to,
            $message,
        );
        $this->assertAccepted($outcome);

        return new SmsResult('smsonlinegh', $outcome->messageId);
    }

    private function assertAccepted(SmsOnlineGhOutcome $o): void
    {
        if ($o->http !== 200) {
            if (in_array($o->http, self::PERMANENT_HTTP, true)) {
                throw new PermanentNotificationException("SMSOnlineGH rejected the request (HTTP {$o->http}).");
            }
            throw new TransientNotificationException("SMSOnlineGH temporarily unavailable (HTTP {$o->http}).");
        }

        if ($o->handshake !== 0) {
            $code = (int) $o->handshake;
            if (in_array($code, self::PERMANENT_HANDSHAKE, true)) {
                throw new PermanentNotificationException("SMSOnlineGH rejected the request (handshake {$code}).");
            }
            throw new TransientNotificationException("SMSOnlineGH reported a failure (handshake {$code}).");
        }

        // A rejected destination still comes back with HTTP 200 and handshake OK.
        $status = $o->statusId;
        if ($status !== null && in_array($status, self::ACCEPTED_STATUS, true)) {
            return;
        }
        if ($status !== null && in_array($status, self::PERMANENT_STATUS, true)) {
            throw new PermanentNotificationException("SMSOnlineGH rejected the message (status {$status}).");
        }
        throw new TransientNotificationException('SMSOnlineGH reported a failure (status '.($status ?? 'none').').');
    }
}
