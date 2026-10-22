<?php

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Exceptions\PermanentNotificationException;
use App\Modules\Notifications\Support\NotificationPayload;

/**
 * Customer SMS builders. Output is GSM-7 basic charset only and never exceeds 306 characters (2 segments).
 * Money is passed in pesewas; free-text reasons are deliberately never accepted.
 */
final class SmsMessage
{
    public const TEMPLATE_CUSTOMER_ORDER_UPDATED = 'sms.customer_order_updated';

    public const TEMPLATE_CUSTOMER_ORDER_PAID = 'sms.customer_order_paid';

    public const TEMPLATE_CUSTOMER_READY_FOR_DISPATCH = 'sms.customer_ready_for_dispatch';

    public const TEMPLATE_CUSTOMER_DISPATCHED = 'sms.customer_dispatched';

    public const TEMPLATE_CUSTOMER_DELIVERED = 'sms.customer_delivered';

    public const TEMPLATE_CUSTOMER_CANCELLATION_RECEIVED = 'sms.customer_cancellation_received';

    public const TEMPLATE_CUSTOMER_REFUND_APPROVED = 'sms.customer_refund_approved';

    public const TEMPLATE_CUSTOMER_REFUND_REJECTED = 'sms.customer_refund_rejected';

    public const TEMPLATE_CUSTOMER_REFUND_FAILED = 'sms.customer_refund_failed';

    public const TEMPLATE_CUSTOMER_REFUND_COMPLETED = 'sms.customer_refund_completed';

    public const TEMPLATE_COMPLAINT_RECEIVED = 'complaint-received';

    public const MAX = 306;

    private const BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const REPLACEMENTS = [
        '€' => 'EUR', "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{2013}" => '-', "\u{2014}" => '-',
        "\u{2026}" => '...', "\u{00A0}" => ' ', "\u{00D7}" => 'x', '[' => '(', ']' => ')', '{' => '(', '}' => ')', '\\' => '/',
        '^' => '', '~' => '-', '|' => '/', "\t" => ' ',
    ];

    public static function forPayload(NotificationPayload $payload): string
    {
        return match ($payload->template) {
            self::TEMPLATE_CUSTOMER_ORDER_UPDATED => self::customerOrderUpdated($payload->data),
            self::TEMPLATE_CUSTOMER_ORDER_PAID => self::customerOrderPaid($payload->data),
            self::TEMPLATE_CUSTOMER_READY_FOR_DISPATCH => self::customerReadyForDispatch($payload->data),
            self::TEMPLATE_CUSTOMER_DISPATCHED => self::customerDispatched($payload->data),
            self::TEMPLATE_CUSTOMER_DELIVERED => self::customerDelivered($payload->data),
            self::TEMPLATE_CUSTOMER_CANCELLATION_RECEIVED => self::customerCancellationReceived($payload->data),
            self::TEMPLATE_CUSTOMER_REFUND_APPROVED => self::customerRefundApproved($payload->data),
            self::TEMPLATE_CUSTOMER_REFUND_REJECTED => self::customerRefundRejected($payload->data),
            self::TEMPLATE_CUSTOMER_REFUND_FAILED => self::customerRefundFailed($payload->data),
            self::TEMPLATE_CUSTOMER_REFUND_COMPLETED => self::customerRefundCompleted($payload->data),
            self::TEMPLATE_COMPLAINT_RECEIVED => self::complaintReceived($payload->data),
            default => throw new PermanentNotificationException('Unknown SMS template.'),
        };
    }

    /** Customer-facing: nothing internal (settlement notes never reach templates).
     *
     * @param  array<string, mixed>  $d
     */
    public static function customerOrderUpdated(array $d): string
    {
        $number = self::clean((string) ($d['orderNumber'] ?? ''), 30);
        $total = self::ghs($d['amountMinor'] ?? 0);
        $head = 'Vidorck Hair: order '.$number." was updated as agreed.\nItems: ";
        $tail = "\nNew items total ".$total."\nTrack: ".self::trackLink();

        $lines = [];
        foreach ((array) ($d['items'] ?? []) as $item) {
            $item = (array) $item;
            $size = self::clean((string) ($item['size'] ?? ''), 12);
            $lines[] = self::clean((string) ($item['name'] ?? ''), 28).($size === '' ? '' : ' '.$size).' x'.max(1, (int) ($item['qty'] ?? 1));
        }
        $kept = [];
        foreach ($lines as $i => $line) {
            $remaining = count($lines) - $i - 1;
            if (mb_strlen($head.implode(', ', [...$kept, $line]).($remaining > 0 ? ', +'.$remaining.' more' : '').$tail) > self::MAX) {
                break;
            }
            $kept[] = $line;
        }
        $hidden = count($lines) - count($kept);
        $list = implode(', ', $kept).($hidden > 0 ? ($kept === [] ? '' : ', ').'+'.$hidden.' more' : '');

        return self::normalise($head.$list.$tail);
    }

    /** Customer-facing order-paid confirmation: first name, total paid and the tracking link.
     *
     * @param  array<string, mixed>  $d
     */
    public static function customerOrderPaid(array $d): string
    {
        return self::greeted($d, 'Payment of '.self::ghs($d['amountMinor'] ?? 0).' received. Order '.self::orderNumber($d).' is confirmed and being prepared. Track: '.self::trackLink());
    }

    /** @param array<string, mixed> $d */
    public static function customerReadyForDispatch(array $d): string
    {
        return self::greeted($d, 'Order '.self::orderNumber($d)." is packed and ready. We'll WhatsApp or call you to confirm the courier and delivery fee before dispatch.");
    }

    /** @param array<string, mixed> $d */
    public static function customerDispatched(array $d): string
    {
        return self::greeted($d, 'Order '.self::orderNumber($d).' is on its way. Rider details will follow on WhatsApp, so please keep your phone nearby.');
    }

    /** @param array<string, mixed> $d */
    public static function customerDelivered(array $d): string
    {
        return self::greeted($d, 'Order '.self::orderNumber($d).' has been delivered. We hope you love it! Thank you for choosing Vidorck Hair.');
    }

    /** Acknowledges the request only; the customer's reason is deliberately not accepted.
     *
     * @param  array<string, mixed>  $d
     */
    public static function customerCancellationReceived(array $d): string
    {
        return self::normalise('Vidorck Hair: we got your cancellation request for order '.self::orderNumber($d).". This doesn't cancel the order yet; we'll be in touch.");
    }

    /** Refund texts never carry the decision reason or provider references.
     *
     * @param  array<string, mixed>  $d
     */
    public static function customerRefundApproved(array $d): string
    {
        return self::normalise('Vidorck Hair: your refund of '.self::ghs($d['amountMinor'] ?? 0).' for order '.self::orderNumber($d)." is approved. We'll text you when it's sent.");
    }

    /** @param array<string, mixed> $d */
    public static function customerRefundRejected(array $d): string
    {
        return self::normalise("Vidorck Hair: we couldn't approve the refund for order ".self::orderNumber($d).'. Contact us if you have questions.');
    }

    /** @param array<string, mixed> $d */
    public static function customerRefundFailed(array $d): string
    {
        return self::normalise('Vidorck Hair: there was a problem sending your refund for order '.self::orderNumber($d).". We're looking into it.");
    }

    /** @param array<string, mixed> $d */
    public static function customerRefundCompleted(array $d): string
    {
        return self::normalise('Vidorck Hair: your refund of '.self::ghs($d['amountMinor'] ?? 0).' for order '.self::orderNumber($d).' is complete. It may take a few days to show.');
    }

    /** @param array<string, mixed> $d */
    public static function complaintReceived(array $d): string
    {
        $reference = self::clean((string) ($d['reference'] ?? ''), 16);

        return self::normalise("Vidorck Hair: we've received your message. Your reference is {$reference}. We'll be in touch.");
    }

    /** Replaces everything outside GSM-7 basic, then enforces the hard cap. */
    public static function normalise(string $text): string
    {
        $text = strtr($text, self::REPLACEMENTS);
        $out = '';
        foreach (mb_str_split($text) as $char) {
            if (str_contains(self::BASIC, $char)) {
                $out .= $char;

                continue;
            }
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $char);
            $ascii = is_string($ascii) ? preg_replace('/[^A-Za-z0-9]/', '', $ascii) : '';
            $out .= $ascii !== '' && $ascii !== null ? $ascii : '?';
        }

        return mb_substr($out, 0, self::MAX);
    }

    private static function clean(string $value, int $max): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max - 1)).'.' : $value;
    }

    /** Greets by first name, but drops the name rather than let the text spill into a second (billed) segment.
     *
     * @param  array<string, mixed>  $d
     */
    private static function greeted(array $d, string $body): string
    {
        $name = self::firstName($d);
        if ($name !== '') {
            $text = self::normalise('Vidorck Hair: Hi '.$name.'! '.$body);
            if (mb_strlen($text) <= 160) {
                return $text;
            }
        }

        return self::normalise('Vidorck Hair: Hi! '.$body);
    }

    /** First word only, hard-capped; never the full name.
     *
     * @param  array<string, mixed>  $d
     */
    private static function firstName(array $d): string
    {
        $words = preg_split('/\s+/u', trim((string) ($d['firstName'] ?? '')));

        return mb_substr(preg_replace('/[<>]/', '', $words[0] ?? '') ?? '', 0, 15);
    }

    /** @param array<string, mixed> $d */
    private static function orderNumber(array $d): string
    {
        return self::clean((string) ($d['orderNumber'] ?? ''), 30);
    }

    private static function ghs(mixed $minor): string
    {
        return 'GHS '.number_format(((int) $minor) / 100, 2, '.', '');
    }

    private static function trackLink(): string
    {
        return rtrim((string) config('services.storefront.url'), '/').'/track-order';
    }
}
