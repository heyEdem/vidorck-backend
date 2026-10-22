<?php

namespace App\Modules\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Built only from a frozen payload array, never a live model. Not queued: the SendNotification job
 * sends it synchronously. Only the whitelisted keys read in vars() can ever reach a template, so extra
 * payload keys (reasons, provider references, courier details, tokens) are dropped by construction.
 *
 * Payload keys: orderNumber, customerName, phone, email, message, totalMinor, amountMinor, paymentMethod,
 * deliveryFeeNote, items [{name,size,qty,child?}], delivery {address,landmark,area,region,ghanaPostGps}.
 */
abstract class NotificationMailable extends Mailable
{
    public const DEFAULT_FEE_NOTE = 'The courier delivery fee is not included in your total. Vidorck will confirm the courier price with you separately by WhatsApp or phone.';

    private const PAYMENT_LABELS = ['MOMO' => 'Mobile Money', 'CARD' => 'Card', 'CASH_OR_OTHER' => 'Cash / other'];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data) {}

    /** Template slug; also the view name under mail.notifications. */
    abstract public static function template(): string;

    abstract protected function subjectLine(string $orderNumber): string;

    public function envelope(): Envelope
    {
        $replyTo = (string) config('mail.reply_to.address');

        return new Envelope(
            subject: $this->subjectLine(self::line($this->data['orderNumber'] ?? '', 40)),
            replyTo: $replyTo === '' ? [] : [new Address($replyTo, (string) config('mail.reply_to.name'))],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.notifications.'.static::template(),
            text: 'mail.notifications.'.static::template().'-text',
            with: ['v' => $this->vars()],
        );
    }

    /** @return array<string, mixed> */
    protected function vars(): array
    {
        $d = $this->data;
        $items = [];
        foreach ((array) ($d['items'] ?? []) as $item) {
            $item = (array) $item;
            $items[] = ['name' => self::line($item['name'] ?? '', 120), 'size' => self::line($item['size'] ?? '', 40), 'qty' => max(1, (int) ($item['qty'] ?? 1)), 'child' => (bool) ($item['child'] ?? false)];
        }
        $delivery = [];
        foreach (['address', 'landmark', 'area', 'region', 'ghanaPostGps'] as $key) {
            $value = self::line(((array) ($d['delivery'] ?? []))[$key] ?? '', 200);
            if ($value !== '') {
                $delivery[] = $value;
            }
        }
        $number = self::line($d['orderNumber'] ?? '', 40);
        $payment = self::line($d['paymentMethod'] ?? '', 40);
        $storefront = rtrim((string) config('services.storefront.url'), '/');
        $notes = (array) config('services.notifications');

        return [
            'orderNumber' => $number,
            'customerName' => self::line($d['customerName'] ?? '', 120),
            'firstName' => self::line(preg_split('/\s+/u', trim((string) ($d['customerName'] ?? '')))[0] ?? '', 40),
            'phone' => self::line($d['phone'] ?? '', 40),
            'email' => self::line($d['email'] ?? '', 200),
            'reference' => self::line($d['reference'] ?? '', 16),
            'message' => mb_substr(trim((string) ($d['message'] ?? '')), 0, 5000),
            'items' => $items,
            'delivery' => $delivery,
            'total' => self::money($d['totalMinor'] ?? null) ?? '',
            'amount' => self::money($d['amountMinor'] ?? null),
            'paymentMethod' => self::PAYMENT_LABELS[$payment] ?? $payment,
            'feeNote' => self::line($d['deliveryFeeNote'] ?? '', 400) ?: self::DEFAULT_FEE_NOTE,
            'trackUrl' => $storefront.'/track-order',
            'support' => [
                'email' => (string) config('mail.reply_to.address'),
                'phone' => (string) ($notes['support_phone'] ?? ''),
                'whatsapp' => (string) ($notes['support_whatsapp'] ?? ''),
            ],
        ];
    }

    /** Single-line, bounded text: also keeps header values (subjects) free of CR/LF. */
    protected static function line(mixed $value, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : '')), 0, $max);
    }

    private static function money(mixed $minor): ?string
    {
        return is_numeric($minor) ? 'GHS '.number_format(((int) $minor) / 100, 2, '.', ',') : null;
    }
}
