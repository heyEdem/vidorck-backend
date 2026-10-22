<?php

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Exceptions\PermanentNotificationException;
use App\Modules\Notifications\Exceptions\TransientNotificationException;
use App\Modules\Notifications\Support\NotificationPayload;
use App\Modules\Notifications\Support\SendResult;
use Illuminate\Support\Facades\Mail;
use Resend\Exceptions\ErrorException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;

/** Sends synchronously inside the job. Provider error text is never copied into thrown messages. */
final class EmailNotificationSender implements NotificationSender
{
    /** @var array<string, class-string<NotificationMailable>> */
    public const MAILABLES = [
        'order-confirmation' => OrderConfirmation::class,
        'ready-for-dispatch' => ReadyForDispatch::class,
        'dispatched' => Dispatched::class,
        'delivered' => Delivered::class,
        'order-updated' => OrderUpdated::class,
        'cancellation-received' => CancellationReceived::class,
        'refund-approved' => RefundApproved::class,
        'refund-rejected' => RefundRejected::class,
        'refund-completed' => RefundCompleted::class,
        'refund-failed' => RefundFailed::class,
        'complaint-received' => ComplaintReceived::class,
    ];

    public function channel(): NotificationChannel
    {
        return NotificationChannel::EMAIL;
    }

    public function send(string $recipient, NotificationPayload $payload): SendResult
    {
        $class = self::MAILABLES[$payload->template] ?? throw new PermanentNotificationException('Unknown email template.');

        try {
            $sent = Mail::to($recipient)->send(new $class($payload->data));
        } catch (\Throwable $e) {
            throw self::map($e);
        }

        $original = $sent?->getOriginalMessage();
        $id = $original instanceof Email ? $original->getHeaders()->get('X-Resend-Email-ID')?->getBodyAsString() : null;

        return new SendResult((string) config('mail.default'), $id === null || $id === '' ? null : mb_substr($id, 0, 100));
    }

    public static function map(\Throwable $e): \Throwable
    {
        $status = null;
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof ErrorException) {
                $status = $x->getErrorCode();
                break;
            }
            if ($x instanceof TransportExceptionInterface && $x->getCode() >= 400) {
                $status = $x->getCode();
            }
        }

        if ($status !== null && $status >= 400 && $status < 500 && ! in_array($status, [408, 409, 425, 429], true)) {
            return new PermanentNotificationException("Resend rejected the email (HTTP {$status}).");
        }

        return new TransientNotificationException($status === null ? 'Email could not be sent right now.' : "Resend temporarily unavailable (HTTP {$status}).");
    }
}
