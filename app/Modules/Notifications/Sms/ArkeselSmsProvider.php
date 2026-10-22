<?php

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Exceptions\PermanentNotificationException;
use App\Modules\Notifications\Exceptions\TransientNotificationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Arkesel v2. No HTTP auto-retry: the queued job owns retries. Provider text is never echoed into errors. */
final class ArkeselSmsProvider implements SmsProvider
{
    public function send(string $toE164, string $message): SmsResult
    {
        $key = (string) config('services.arkesel.key');
        if ($key === '') {
            throw new TransientNotificationException('Arkesel API key is not configured.');
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.arkesel.base_url'), '/'))
                ->withHeaders(['api-key' => $key])
                ->acceptJson()->asJson()
                ->connectTimeout(3)->timeout(10)
                ->post('/api/v2/sms/send', [
                    'sender' => (string) config('services.arkesel.sender_id'),
                    'message' => $message,
                    'recipients' => [ltrim($toE164, '+')],
                ]);
        } catch (ConnectionException) {
            throw new TransientNotificationException('Arkesel request timed out or could not connect.');
        }

        $status = $response->status();
        if ($status === 429 || $status === 408 || $status >= 500) {
            throw new TransientNotificationException("Arkesel temporarily unavailable (HTTP {$status}).");
        }
        if ($status >= 400) {
            throw new PermanentNotificationException(
                in_array($status, [401, 403], true)
                    ? "Arkesel rejected the credentials (HTTP {$status})."
                    : "Arkesel rejected the request (HTTP {$status}).",
            );
        }

        return $this->parse($response);
    }

    private function parse(Response $response): SmsResult
    {
        $body = $response->json();
        if (! is_array($body) || ! isset($body['status']) || ! is_string($body['status'])) {
            throw new TransientNotificationException('Arkesel returned an unreadable response.');
        }
        if (strtolower($body['status']) !== 'success') {
            // Business error delivered with HTTP 200.
            $text = is_string($body['message'] ?? null) ? $body['message'] : '';
            if (preg_match('/api[\s_-]?key|authenticat|unauthori[sz]ed|invalid\s+(sender|recipient|phone|number)/i', $text)) {
                throw new PermanentNotificationException('Arkesel rejected the credentials, sender or recipient.');
            }
            throw new TransientNotificationException('Arkesel reported a failure.');
        }

        return new SmsResult('arkesel', $this->messageId($body));
    }

    /** @param array<mixed> $body */
    private function messageId(array $body): ?string
    {
        $data = $body['data'] ?? null;
        $first = is_array($data) ? (array_is_list($data) ? ($data[0] ?? null) : $data) : null;
        foreach ([$first, $body] as $source) {
            if (! is_array($source)) {
                continue;
            }
            foreach (['id', 'message_id', 'messageId'] as $field) {
                $id = $source[$field] ?? null;
                if ((is_string($id) || is_int($id)) && (string) $id !== '') {
                    return mb_substr((string) $id, 0, 100);
                }
            }
        }

        return null;
    }
}
