<?php

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Exceptions\PermanentNotificationException;
use App\Modules\Notifications\Exceptions\TransientNotificationException;
use App\Modules\Notifications\Sms\ArkeselSmsProvider;
use App\Modules\Notifications\Sms\LogSmsProvider;
use App\Modules\Notifications\Sms\SmsMessage;
use App\Modules\Notifications\Sms\SmsNotificationSender;
use App\Modules\Notifications\Sms\SmsProvider;
use App\Modules\Notifications\Support\NotificationPayload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config([
        'services.arkesel.key' => 'secret-key', 'services.arkesel.base_url' => 'https://sms.test',
        'services.arkesel.sender_id' => 'VIDORCK',
    ]);
    Http::preventStrayRequests();
});

function arkesel(): ArkeselSmsProvider
{
    return new ArkeselSmsProvider;
}

it('sends one recipient with api-key header and returns the message id', function (): void {
    Http::fake(['sms.test/*' => Http::response(['status' => 'success', 'data' => [['recipient' => '233241234567', 'id' => 'abc-1']]])]);

    $r = arkesel()->send('+233241234567', 'Hi');

    expect($r->provider)->toBe('arkesel')->and($r->messageId)->toBe('abc-1');
    Http::assertSent(fn (Request $q) => $q->url() === 'https://sms.test/api/v2/sms/send'
        && $q->hasHeader('api-key', 'secret-key')
        && $q['sender'] === 'VIDORCK' && $q['recipients'] === ['233241234567'] && $q['message'] === 'Hi');
    Http::assertSentCount(1);
});

it('accepts success without an id', function (): void {
    Http::fake(['*' => Http::response(['status' => 'success'])]);
    expect(arkesel()->send('+233241234567', 'Hi')->messageId)->toBeNull();
});

it('treats an HTTP 200 business error as a failure', function (): void {
    Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Insufficient balance for 0241234567'])]);
    expect(fn () => arkesel()->send('+233241234567', 'Hi'))->toThrow(TransientNotificationException::class, 'Arkesel reported a failure.');
});

it('treats an HTTP 200 invalid api key as permanent', function (): void {
    Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Invalid API key'])]);
    expect(fn () => arkesel()->send('+233241234567', 'Hi'))->toThrow(PermanentNotificationException::class);
});

it('maps 401 and 422 to permanent without leaking provider text', function (int $status): void {
    Http::fake(['*' => Http::response(['message' => 'bad 0241234567 secret-key'], $status)]);
    try {
        arkesel()->send('+233241234567', 'Hi');
        $this->fail('expected exception');
    } catch (PermanentNotificationException $e) {
        expect($e->getMessage())->not->toContain('0241234567')->not->toContain('secret');
    }
})->with([401, 403, 422]);

it('maps 429 and 5xx to transient', function (int $status): void {
    Http::fake(['*' => Http::response('x', $status)]);
    expect(fn () => arkesel()->send('+233241234567', 'Hi'))->toThrow(TransientNotificationException::class);
    Http::assertSentCount(1); // no automatic retry
})->with([429, 500, 503]);

it('maps timeouts to transient', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28 for 0241234567'));
    expect(fn () => arkesel()->send('+233241234567', 'Hi'))->toThrow(TransientNotificationException::class, 'timed out');
});

it('treats malformed replies as transient', function (mixed $body): void {
    Http::fake(['*' => Http::response($body)]);
    expect(fn () => arkesel()->send('+233241234567', 'Hi'))->toThrow(TransientNotificationException::class, 'unreadable');
})->with(['not json at all', [['unexpected' => true]], [[1, 2]]]);

it('fails transiently when the api key is missing, without any request', function (): void {
    config(['services.arkesel.key' => null]);
    expect(fn () => arkesel()->send('+233241234567', 'Hi'))->toThrow(TransientNotificationException::class);
    Http::assertNothingSent();
});

it('binds by driver and rejects unknown drivers', function (): void {
    config(['services.sms.driver' => 'log']);
    app()->forgetInstance(SmsProvider::class);
    expect(app(SmsProvider::class))->toBeInstanceOf(LogSmsProvider::class);

    config(['services.sms.driver' => 'arkesel']);
    app()->forgetInstance(SmsProvider::class);
    expect(app(SmsProvider::class))->toBeInstanceOf(ArkeselSmsProvider::class);

    config(['services.sms.driver' => 'twilio']);
    app()->forgetInstance(SmsProvider::class);
    expect(fn () => app(SmsProvider::class))->toThrow(InvalidArgumentException::class);
});

it('log driver sends nothing and logs only masked recipient and length', function (): void {
    Log::spy();
    $r = (new LogSmsProvider)->send('+233241234567', 'secret body text');
    expect($r->provider)->toBe('log');
    Log::shouldHaveReceived('info')->withArgs(fn ($m, $ctx) => $ctx['length'] === 16 && ! str_contains(json_encode($ctx), '241234567') && ! str_contains(json_encode($ctx), 'secret'))->once();
    Http::assertNothingSent();
});

it('is registered as the SMS sender under the tag', function (): void {
    $senders = iterator_to_array(app()->tagged('notification.senders'));
    expect(collect($senders)->contains(fn ($s) => $s instanceof SmsNotificationSender && $s->channel() === NotificationChannel::SMS))->toBeTrue();
});

it('sender builds the message from the payload and maps the result', function (): void {
    config(['services.sms.driver' => 'arkesel']);
    app()->forgetInstance(SmsProvider::class);
    Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['id' => 'm9']])]);
    $sender = app(SmsNotificationSender::class);

    $r = $sender->send('+233241234567', new NotificationPayload(SmsMessage::TEMPLATE_CUSTOMER_CANCELLATION_RECEIVED, ['orderNumber' => 'VH-1']));

    expect($r->provider)->toBe('arkesel')->and($r->messageId)->toBe('m9');
    expect(fn () => $sender->send('+233241234567', new NotificationPayload('nope')))->toThrow(PermanentNotificationException::class);
});

// SmsMessage

function orderData(int $items = 2, array $over = []): array
{
    return $over + [
        'orderNumber' => 'VH-2026-000123', 'customerName' => 'Ama Mensah', 'phone' => '0241234567', 'totalMinor' => 15050, 'area' => 'East Legon',
        'items' => array_map(fn ($i) => ['name' => "Shea Butter Pomade $i", 'size' => '250ml', 'qty' => 2], range(1, $items)),
    ];
}

it('normalises to the GSM-7 basic charset', function (): void {
    $m = SmsMessage::normalise("Caf\u{00E9} \u{201C}quoted\u{201D} \u{20AC}5 [x] \u{4F60}\u{597D} \u{00E7}\u{00F1}\u{00FF} \u{1F600}");
    expect($m)->toContain('Café', '"quoted"', 'EUR5', '(x)')->not->toContain('€')->not->toContain('[')->not->toContain('你')->not->toContain('😀');
    foreach (mb_str_split($m) as $c) {
        expect(str_contains("@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà", $c))->toBeTrue();
    }
});

it('hard caps any input at 306', function (): void {
    expect(mb_strlen(SmsMessage::normalise(str_repeat('a', 1000))))->toBe(306);
});

it('builds the customer order-updated text with the new items, total and tracking link, no admin link', function (): void {
    config(['services.storefront.url' => 'https://shop.test']);
    $m = SmsMessage::customerOrderUpdated(['orderNumber' => 'VH-9', 'amountMinor' => 5400, 'settlementNote' => 'internal',
        'items' => [['name' => 'Shampoo', 'size' => '250ml', 'qty' => 3], ['name' => 'Conditioner', 'size' => '', 'qty' => 1]]]);
    expect($m)->toContain('VH-9', 'Shampoo 250ml x3', 'Conditioner x1', 'GHS 54.00', 'https://shop.test/track-order')
        ->not->toContain('admin.test')->not->toContain('internal');
});

it('keeps the order-updated text within 306 characters by summarising extra items', function (): void {
    config(['services.storefront.url' => 'https://shop.test']);
    $items = array_map(static fn (int $i): array => ['name' => 'Product number '.$i, 'size' => '250ml', 'qty' => 1], range(1, 15));
    $m = SmsMessage::customerOrderUpdated(['orderNumber' => 'VH-9', 'amountMinor' => 5400, 'items' => $items]);
    expect(mb_strlen($m))->toBeLessThanOrEqual(306)->and($m)->toContain('more', 'GHS 54.00', 'https://shop.test/track-order');
});

it('routes the customer order-updated template through forPayload', function (): void {
    config(['services.storefront.url' => 'https://shop.test']);
    expect(SmsMessage::forPayload(new NotificationPayload(SmsMessage::TEMPLATE_CUSTOMER_ORDER_UPDATED, ['orderNumber' => 'VH-9', 'amountMinor' => 100])))
        ->toContain('VH-9');
});

it('builds the customer order-paid text with first name, paid total and tracking link, within one segment', function (): void {
    config(['services.storefront.url' => 'https://shop.test/']);
    $m = SmsMessage::forPayload(new NotificationPayload(SmsMessage::TEMPLATE_CUSTOMER_ORDER_PAID, ['orderNumber' => 'VH-2026-000123', 'amountMinor' => 15050, 'firstName' => 'Ama']));
    expect($m)->toBe('Vidorck Hair: Hi Ama! Payment of GHS 150.50 received. Order VH-2026-000123 is confirmed and being prepared. Track: https://shop.test/track-order')
        ->and(mb_strlen($m))->toBeLessThanOrEqual(160);
});

it('builds the customer milestone texts through forPayload, each within one segment', function (): void {
    $texts = [
        SmsMessage::TEMPLATE_CUSTOMER_READY_FOR_DISPATCH => "Vidorck Hair: Hi Ama! Order VH-9 is packed and ready. We'll WhatsApp or call you to confirm the courier and delivery fee before dispatch.",
        SmsMessage::TEMPLATE_CUSTOMER_DISPATCHED => 'Vidorck Hair: Hi Ama! Order VH-9 is on its way. Rider details will follow on WhatsApp, so please keep your phone nearby.',
        SmsMessage::TEMPLATE_CUSTOMER_DELIVERED => 'Vidorck Hair: Hi Ama! Order VH-9 has been delivered. We hope you love it! Thank you for choosing Vidorck Hair.',
    ];
    foreach ($texts as $template => $expected) {
        $m = SmsMessage::forPayload(new NotificationPayload($template, ['orderNumber' => 'VH-9', 'firstName' => 'Ama']));
        expect($m)->toBe($expected)->and(mb_strlen($m))->toBeLessThanOrEqual(160);
    }
});

it('greets without a name when there is none', function (): void {
    expect(SmsMessage::forPayload(new NotificationPayload(SmsMessage::TEMPLATE_CUSTOMER_DISPATCHED, ['orderNumber' => 'VH-9'])))
        ->toBe('Vidorck Hair: Hi! Order VH-9 is on its way. Rider details will follow on WhatsApp, so please keep your phone nearby.');
});

it('drops the name rather than spill into a second segment', function (): void {
    config(['services.storefront.url' => 'https://vidorckhair.com']);
    $data = ['orderNumber' => 'VHI-2026-000010', 'amountMinor' => 1234500, 'firstName' => 'Christabella'];
    $paid = SmsMessage::forPayload(new NotificationPayload(SmsMessage::TEMPLATE_CUSTOMER_ORDER_PAID, $data));
    expect($paid)->toStartWith('Vidorck Hair: Hi! Payment of GHS 12345.00 received.')->and(mb_strlen($paid))->toBeLessThanOrEqual(160);
    foreach ([SmsMessage::TEMPLATE_CUSTOMER_READY_FOR_DISPATCH, SmsMessage::TEMPLATE_CUSTOMER_DISPATCHED, SmsMessage::TEMPLATE_CUSTOMER_DELIVERED] as $template) {
        $m = SmsMessage::forPayload(new NotificationPayload($template, $data));
        expect($m)->toStartWith('Vidorck Hair: Hi Christabella!')->and(mb_strlen($m))->toBeLessThanOrEqual(160);
    }
});

it('keeps only a clean, short first name', function (): void {
    $m = SmsMessage::forPayload(new NotificationPayload(SmsMessage::TEMPLATE_CUSTOMER_DELIVERED, ['orderNumber' => 'VH-9', 'firstName' => "  Ama\n<b>  "]));
    expect($m)->toStartWith('Vidorck Hair: Hi Ama!')->not->toContain('<b>')->not->toContain("\n");
    $long = SmsMessage::forPayload(new NotificationPayload(SmsMessage::TEMPLATE_CUSTOMER_DELIVERED, ['orderNumber' => 'VH-9', 'firstName' => 'Abcdefghijklmnopqrstuvwxyz']));
    expect($long)->toStartWith('Vidorck Hair: Hi Abcdefghijklmno!');
});

it('builds the customer cancellation acknowledgement without ever echoing a reason', function (): void {
    $m = SmsMessage::forPayload(new NotificationPayload(SmsMessage::TEMPLATE_CUSTOMER_CANCELLATION_RECEIVED, ['orderNumber' => 'VH-9', 'reason' => 'changed my mind']));
    expect($m)->toBe("Vidorck Hair: we got your cancellation request for order VH-9. This doesn't cancel the order yet; we'll be in touch.")
        ->and(mb_strlen($m))->toBeLessThanOrEqual(160);
});

it('builds the customer refund texts through forPayload, each within one segment', function (): void {
    $data = ['orderNumber' => 'VH-9', 'amountMinor' => 15050, 'reason' => 'private', 'providerReference' => 'rr-1'];
    $texts = [
        SmsMessage::TEMPLATE_CUSTOMER_REFUND_APPROVED => "Vidorck Hair: your refund of GHS 150.50 for order VH-9 is approved. We'll text you when it's sent.",
        SmsMessage::TEMPLATE_CUSTOMER_REFUND_REJECTED => "Vidorck Hair: we couldn't approve the refund for order VH-9. Contact us if you have questions.",
        SmsMessage::TEMPLATE_CUSTOMER_REFUND_FAILED => "Vidorck Hair: there was a problem sending your refund for order VH-9. We're looking into it.",
        SmsMessage::TEMPLATE_CUSTOMER_REFUND_COMPLETED => 'Vidorck Hair: your refund of GHS 150.50 for order VH-9 is complete. It may take a few days to show.',
    ];
    foreach ($texts as $template => $expected) {
        $m = SmsMessage::forPayload(new NotificationPayload($template, $data));
        expect($m)->toBe($expected)->and(mb_strlen($m))->toBeLessThanOrEqual(160)->and($m)->not->toContain('private')->not->toContain('rr-1');
    }
});
