<?php

namespace App\Modules\Checkout\Http\Controllers;

use App\Modules\Checkout\Jobs\ProcessPaystackEvent;
use App\Modules\Checkout\Models\PaymentEvent;
use App\Modules\Checkout\Paystack\PaymentDataAllowlist;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PaystackWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $signature = $request->header('x-paystack-signature');
        $secret = config('services.paystack.secret_key');
        $expected = is_string($secret) && $secret !== '' ? hash_hmac('sha512', $raw, $secret) : '';

        if (! is_string($signature) || preg_match('/^[a-fA-F0-9]{128}$/D', $signature) !== 1
            || $expected === '' || ! hash_equals($expected, strtolower($signature))) {
            Log::warning('Invalid Paystack webhook signature.', ['request_id' => $request->attributes->get('request_id')]);

            return $this->error('Invalid signature.', 'INVALID_SIGNATURE', 401);
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error('Malformed webhook payload.', 'INVALID_WEBHOOK_PAYLOAD', 400);
        }

        if (! is_array($payload) || ! is_string($payload['event'] ?? null) || $payload['event'] === '') {
            return $this->error('Invalid webhook payload.', 'INVALID_WEBHOOK_PAYLOAD', 422);
        }
        $eventType = $payload['event'];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = $data['reference'] ?? null;
        if ($eventType === 'charge.success' && (! is_string($reference) || $reference === '')) {
            return $this->error('Charge reference is required.', 'INVALID_WEBHOOK_PAYLOAD', 422);
        }
        if (str_starts_with($eventType, 'refund.')) {
            // Refund events identify the original charge, not a charge reference of their own.
            $transaction = $data['transaction_reference'] ?? (is_array($data['transaction'] ?? null) ? ($data['transaction']['reference'] ?? null) : ($data['transaction'] ?? null));
            $reference = is_string($transaction) ? $transaction : '';
            if (! isset($data['transaction_reference']) && $reference !== '') {
                $data['transaction_reference'] = $reference;
            }
        }
        if (! is_string($reference)) {
            $reference = '';
        }

        $providerEventId = $this->providerEventId($eventType, $data, $reference, $raw);
        $receipt = [
            'provider' => 'paystack', 'provider_event_id' => $providerEventId, 'event_type' => $eventType,
            'reference' => $reference, 'signature_valid' => true,
            'payload_redacted_json' => PaymentDataAllowlist::snapshot($data),
        ];

        try {
            DB::transaction(function () use ($receipt): void {
                $event = PaymentEvent::query()->where('provider', 'paystack')->where('provider_event_id', $receipt['provider_event_id'])->first();
                if ($event !== null) {
                    if ($event->processed_at === null) {
                        ProcessPaystackEvent::dispatch((int) $event->id)->onConnection('database')->beforeCommit();
                    }

                    return;
                }

                $event = PaymentEvent::query()->create($receipt);
                ProcessPaystackEvent::dispatch((int) $event->id)->onConnection('database')->beforeCommit();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent insert may win the unique key. Requeue only an unprocessed receipt.
            $event = PaymentEvent::query()->where('provider', 'paystack')->where('provider_event_id', $providerEventId)->first();
            if ($event === null) {
                throw $exception;
            }
            if ($event->processed_at === null) {
                ProcessPaystackEvent::dispatch((int) $event->id)->onConnection('database')->beforeCommit();
            }
        }

        return response()->json(['received' => true]);
    }

    /** @param array<string, mixed> $data */
    private function providerEventId(string $event, array $data, string $reference, string $raw): string
    {
        if ($event === 'charge.success' && $reference !== '') {
            return $event.':'.$reference;
        }
        // Refund events dedupe on the refund itself, never the charge reference: refund A failing and a later refund B
        // on the same charge are distinct events, and the event type prefix keeps them apart from charge.success.
        $refundReference = $data['refund_reference'] ?? null;
        if (str_starts_with($event, 'refund.') && is_string($refundReference) && $refundReference !== '') {
            return $event.':'.$refundReference;
        }
        $id = $data['id'] ?? null;
        if (is_string($id) || is_int($id)) {
            return $event.':'.(string) $id;
        }

        return $event.':'.hash('sha256', $raw);
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code, 'errors' => (object) []], $status);
    }
}
