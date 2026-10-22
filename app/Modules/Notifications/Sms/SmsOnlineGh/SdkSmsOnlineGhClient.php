<?php

namespace App\Modules\Notifications\Sms\SmsOnlineGh;

use Closure;
use Zenoph\Notify\Enums\AuthModel;
use Zenoph\Notify\Enums\SMSType;
use Zenoph\Notify\Request\RequestException;
use Zenoph\Notify\Request\SMSRequest;
use Zenoph\Notify\Response\MessageResponse;

/** Builds a fresh SMSRequest per send: the SDK accumulates destinations, so a request is never reused. */
final class SdkSmsOnlineGhClient implements SmsOnlineGhClient
{
    /** @var Closure(): SMSRequest */
    private Closure $factory;

    /** @param (Closure(): SMSRequest)|null $factory */
    public function __construct(?Closure $factory = null)
    {
        $this->factory = $factory ?? static fn (): SMSRequest => new SMSRequest;
    }

    public function send(string $host, string $apiKey, string $sender, string $to, string $message): SmsOnlineGhOutcome
    {
        try {
            $request = ($this->factory)();
            $request->setHost($host);
            $request->useSecureConnection(true); // system CA store, never the bundled cacert.pem
            $request->setAuthModel(AuthModel::API_KEY);
            $request->setAuthApiKey($apiKey);
            $request->setSender($sender);
            $request->setMessage($message);
            $request->setSMSType(SMSType::GSM_DEFAULT);
            $request->addDestination($to);

            $response = $request->submit();
        } catch (RequestException $e) {
            return new SmsOnlineGhOutcome(200, $e->getRequestHandshake());
        } catch (\Throwable $e) {
            return new SmsOnlineGhOutcome($this->httpCode($e->getMessage()));
        }

        $statusId = $messageId = null;

        try {
            $items = ($response instanceof MessageResponse ? $response->getReport() : null)?->getDestinations()->getItems() ?? [];
            if (isset($items[0])) {
                $statusId = $this->safe(static fn () => $items[0]->getStatusId());
                $id = $this->safe(static fn () => $items[0]->getMessageId());
                $messageId = is_string($id) && $id !== '' ? mb_substr($id, 0, 100) : null;
            }
        } catch (\Throwable) {
            // unreadable report: handshake OK but no destination status
        }

        return new SmsOnlineGhOutcome(200, $response->getRequestHandShake(), is_int($statusId) ? $statusId : null, $messageId);
    }

    /** Typed SDK properties throw Error when the server omitted the field. */
    private function safe(Closure $read): mixed
    {
        try {
            return $read();
        } catch (\Throwable) {
            return null;
        }
    }

    /** The SDK reports HTTP failures as generic exceptions; recover the status from its fixed wording. */
    private function httpCode(string $message): int
    {
        return match (true) {
            $message === 'Bad request.' => 400,
            $message === 'Unauthorised request.' => 401,
            $message === 'Forbidden request.' => 403,
            $message === 'Request resource not found.' => 404,
            $message === 'Request method not allowed.' => 405,
            $message === 'Response content type is not acceptable.' => 406,
            $message === 'Request could not be processed.' => 422,
            $message === 'Internal server error.' => 500,
            preg_match('/^Unknown request error <(\d+)>\.$/', $message, $m) === 1 => (int) $m[1],
            default => 0,
        };
    }
}
