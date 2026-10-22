<?php

namespace App\Modules\Checkout\Paystack;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class PaystackClient
{
    /** Monetary calls: connect 3s + 15s total. Two sequential calls (lookup + POST) stay well under the 60s refund claim window. */
    public const MONETARY_TIMEOUT_SECONDS = 15;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function initialize(array $payload): array
    {
        return $this->data($this->send(fn () => $this->request()->post('/transaction/initialize', $payload)));
    }

    private function request(int $timeout = 10): PendingRequest
    {
        $key = config('services.paystack.secret_key');
        if (! is_string($key) || $key === '') {
            throw new PaystackUnavailable;
        }

        return Http::baseUrl((string) config('services.paystack.base_url'))->withToken($key)->acceptJson()->asJson()->connectTimeout(3)->timeout($timeout)->withoutRedirecting();
    }

    private function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new PaystackUnavailable;
        }
        if (! $response->successful()) {
            throw new PaystackUnavailable;
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        $envelope = $response->json();
        if (! is_array($envelope) || ($envelope['status'] ?? null) !== true || ! is_array($envelope['data'] ?? null)) {
            throw new PaystackUnavailable;
        }

        return $envelope['data'];
    }

    /** @return array<string, mixed> */
    public function verify(string $reference): array
    {
        try {
            $response = $this->request()->retry(2, 100, static fn (\Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()))
                ->get('/transaction/verify/'.rawurlencode($reference));
        } catch (RequestException $exception) {
            $response = $exception->response;
        } catch (ConnectionException) {
            throw new PaystackUnavailable;
        }
        if (! $response->successful()) {
            $envelope = $response->json();
            $message = is_array($envelope) ? ($envelope['message'] ?? null) : null;
            if (in_array($response->status(), [400, 404], true) && ($envelope['status'] ?? null) === false
                && is_string($message) && in_array(mb_strtolower(trim($message)), ['transaction reference not found', 'transaction not found'], true)) {
                throw new PaystackTransactionNotFound;
            }
            throw new PaystackUnavailable;
        }

        return $this->data($response);
    }

    /**
     * Full-order refund (website-spec section 16.2). Never retried automatically: a monetary POST that times out or
     * returns 5xx may have succeeded, so that is PaystackUnavailable (outcome unknown). A definitive 4xx refusal is
     * PaystackRefundRejected (nothing was refunded).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function refund(array $payload): array
    {
        try {
            $response = $this->request(self::MONETARY_TIMEOUT_SECONDS)->post('/refund', $payload);
        } catch (ConnectionException) {
            throw new PaystackUnavailable;
        }
        if ($response->clientError() && ! in_array($response->status(), [408, 409, 425, 429], true)) {
            $message = $response->json('message');
            throw new PaystackRefundRejected(is_string($message) ? mb_substr($message, 0, 200) : 'Refund rejected by provider.');
        }
        if (! $response->successful()) {
            throw new PaystackUnavailable;
        }
        $envelope = $response->json();
        if (is_array($envelope) && ($envelope['status'] ?? null) === false) {
            $message = $envelope['message'] ?? null;
            throw new PaystackRefundRejected(is_string($message) ? mb_substr($message, 0, 200) : 'Refund rejected by provider.');
        }

        return $this->data($response);
    }

    /**
     * Existing refunds for a charge (GET /refund). The filter parameter name differs between Paystack reference
     * versions (`transaction` vs `reference`), so both are sent and callers must still match results to the charge.
     * A single safe GET; any failure is PaystackUnavailable.
     *
     * @return list<array<string, mixed>>
     */
    public function listRefunds(string $chargeReference): array
    {
        try {
            $response = $this->request(self::MONETARY_TIMEOUT_SECONDS)->get('/refund', ['transaction' => $chargeReference, 'reference' => $chargeReference, 'currency' => 'GHS']);
        } catch (ConnectionException) {
            throw new PaystackUnavailable;
        }
        if (! $response->successful()) {
            throw new PaystackUnavailable;
        }
        $envelope = $response->json();
        if (! is_array($envelope) || ($envelope['status'] ?? null) !== true || ! is_array($envelope['data'] ?? null)) {
            throw new PaystackUnavailable;
        }

        return array_values(array_filter($envelope['data'], 'is_array'));
    }
}
