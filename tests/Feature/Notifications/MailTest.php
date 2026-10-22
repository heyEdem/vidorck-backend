<?php

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Exceptions\PermanentNotificationException;
use App\Modules\Notifications\Exceptions\TransientNotificationException;
use App\Modules\Notifications\Mail\CancellationReceived;
use App\Modules\Notifications\Mail\ComplaintReceived;
use App\Modules\Notifications\Mail\Delivered;
use App\Modules\Notifications\Mail\Dispatched;
use App\Modules\Notifications\Mail\EmailNotificationSender;
use App\Modules\Notifications\Mail\OrderConfirmation;
use App\Modules\Notifications\Mail\OrderUpdated;
use App\Modules\Notifications\Mail\ReadyForDispatch;
use App\Modules\Notifications\Mail\RefundApproved;
use App\Modules\Notifications\Mail\RefundCompleted;
use App\Modules\Notifications\Mail\RefundFailed;
use App\Modules\Notifications\Mail\RefundRejected;
use App\Modules\Notifications\Support\NotificationPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Resend\Exceptions\ErrorException;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function (): void {
    config([
        'mail.reply_to.address' => 'support@vidorck.test', 'services.storefront.url' => 'https://shop.test',
        'services.notifications.support_phone' => '0302000000',
        'services.notifications.support_whatsapp' => '0241112222',
    ]);
});

function mailData(array $over = []): array
{
    return $over + [
        'orderNumber' => 'VH-2026-000123', 'customerName' => 'Ama Mensah', 'phone' => '0241234567', 'email' => 'ama@example.com',
        'totalMinor' => 15050, 'amountMinor' => 15050, 'paymentMethod' => 'MOMO', 'message' => "Hello\nthere",
        'items' => [['name' => 'Shea Pomade', 'size' => '250ml', 'qty' => 2], ['name' => 'Starter Set', 'size' => '', 'qty' => 1]],
        'delivery' => ['address' => '12 Palm St', 'landmark' => 'Near the mall', 'area' => 'East Legon', 'region' => 'Greater Accra', 'ghanaPostGps' => 'GA-123-4567'],
        // Internal data that must never reach any template.
        'reason' => 'INTERNAL-REASON', 'paystackReference' => 'PSK-SECRET-REF', 'courier' => 'COURIER-RIDER-NAME', 'trackingToken' => 'TOKEN-ABC123',
        'internalNote' => 'INTERNAL-NOTE', 'refundReference' => 'RFD-REF-9',
    ];
}

$all = [
    'order-confirmation' => OrderConfirmation::class, 'ready-for-dispatch' => ReadyForDispatch::class, 'dispatched' => Dispatched::class,
    'delivered' => Delivered::class, 'cancellation-received' => CancellationReceived::class, 'refund-approved' => RefundApproved::class,
    'refund-rejected' => RefundRejected::class, 'refund-completed' => RefundCompleted::class, 'refund-failed' => RefundFailed::class,
    'complaint-received' => ComplaintReceived::class,
    'order-updated' => OrderUpdated::class,
];

it('renders every template in HTML and plain text without missing variables, leaking internals or being queued', function (string $class): void {
    $m = new $class(mailData());
    $html = $m->render();
    $text = view($m->content()->text, $m->content()->with)->render();

    expect($m)->not->toBeInstanceOf(ShouldQueue::class);
    foreach ([$html, $text] as $out) {
        expect($out)->toContain('support@vidorck.test', '0241112222', '0302000000')
            ->not->toContain('INTERNAL-', 'PSK-SECRET', 'COURIER-RIDER', 'TOKEN-ABC123', 'RFD-REF', '$v[')
            ->not->toMatch('/\{\{|\}\}|\{!!|Undefined/');
    }
    expect($text)->not->toContain('<p')->not->toContain('<br');
    expect($m->envelope()->subject)->not->toBe('');
})->with($all);

it('confirmation carries every website-spec 14.3 field', function (): void {
    $m = new OrderConfirmation(mailData());
    foreach (['html' => [$m->render(), '&times; 2'], 'text' => [view($m->content()->text, $m->content()->with)->render(), 'x2']] as [$out, $qty]) {
        expect($out)->toContain('VH-2026-000123', 'Shea Pomade', '250ml', $qty, 'Starter Set', 'GHS 150.50', '12 Palm St', 'East Legon', 'GA-123-4567', 'Mobile Money', 'courier', 'https://shop.test/track-order', 'order number and the phone');
    }
    expect($m->envelope()->subject)->toContain('VH-2026-000123');
});

it('uses a supplied delivery fee note over the default', function (): void {
    expect((new OrderConfirmation(mailData(['deliveryFeeNote' => 'Custom fee wording'])))->render())->toContain('Custom fee wording');
});

it('cancellation email says it does not cancel the order', function (): void {
    $m = new CancellationReceived(mailData());
    expect($m->render())->toContain('This does not cancel your order')
        ->and(view($m->content()->text, $m->content()->with)->render())->toContain('This does not cancel your order');
});

it('complaint acknowledgement includes its reference without exposing submitted content', function (): void {
    $m = new ComplaintReceived(['reference' => 'C-000123', 'message' => 'secret message']);
    expect($m->render())->toContain('C-000123')->not->toContain('secret message');
    expect(view($m->content()->text, $m->content()->with)->render())->toContain('C-000123')->not->toContain('secret message');
    expect($m->envelope()->subject)->not->toMatch('/[\r\n]/');
});

it('customer emails reply to the support inbox', function (): void {
    expect((new Delivered(mailData()))->envelope()->replyTo[0]->address)->toBe('support@vidorck.test');
});

it('refund emails show amounts only when provided', function (): void {
    expect((new RefundCompleted(mailData()))->render())->toContain('GHS 150.50');
    expect((new RefundCompleted(mailData(['amountMinor' => null])))->render())->not->toContain('Amount refunded');
});

it('is registered under the tag for EMAIL', function (): void {
    expect(collect(iterator_to_array(app()->tagged('notification.senders')))->contains(fn ($s) => $s instanceof EmailNotificationSender))->toBeTrue();
});

it('sender sends the mapped mailable synchronously to the recipient', function (string $template, string $class): void {
    Mail::fake();
    $r = app(EmailNotificationSender::class)->send('ama@example.com', new NotificationPayload($template, mailData()));

    Mail::assertSent($class, fn ($m) => $m->hasTo('ama@example.com'));
    Mail::assertNothingQueued();
    expect($r->provider)->toBe(config('mail.default'));
})->with(array_map(null, array_keys($all), array_values($all)));

it('sender rejects unknown templates permanently', function (): void {
    Mail::fake();
    expect(fn () => app(EmailNotificationSender::class)->send('a@b.co', new NotificationPayload('nope')))->toThrow(PermanentNotificationException::class);
    Mail::assertNothingSent();
});

it('maps Resend and transport failures to permanent vs transient', function (): void {
    $wrap = fn (Throwable $inner) => new TransportException('Request to the Resend API failed. Reason: bad ama@example.com', 0, $inner);
    $err = fn (int $code) => new ErrorException(['message' => 'oops ama@example.com', 'statusCode' => $code, 'name' => 'x']);

    foreach ([400, 401, 403, 422] as $code) {
        expect(EmailNotificationSender::map($wrap($err($code))))->toBeInstanceOf(PermanentNotificationException::class);
    }
    foreach ([408, 409, 429, 500, 503] as $code) {
        expect(EmailNotificationSender::map($wrap($err($code))))->toBeInstanceOf(TransientNotificationException::class);
    }
    expect(EmailNotificationSender::map($wrap(new RuntimeException('timeout'))))->toBeInstanceOf(TransientNotificationException::class)
        ->and(EmailNotificationSender::map(new TransportException('x', 429)))->toBeInstanceOf(TransientNotificationException::class)
        ->and(EmailNotificationSender::map(new TransportException('x', 422)))->toBeInstanceOf(PermanentNotificationException::class)
        ->and(EmailNotificationSender::map(new RuntimeException('boom ama@example.com')))->toBeInstanceOf(TransientNotificationException::class);
    expect(EmailNotificationSender::map($wrap($err(422)))->getMessage())->not->toContain('ama@example.com');
});

it('sender surfaces mapped errors from the mailer', function (): void {
    Mail::shouldReceive('to->send')->andThrow(new TransportException('x', 0, new ErrorException(['message' => 'invalid to', 'statusCode' => 422, 'name' => 'validation_error'])));
    expect(fn () => app(EmailNotificationSender::class)->send('a@b.co', new NotificationPayload('delivered', mailData())))->toThrow(PermanentNotificationException::class);
});

it('has the channel EMAIL', function (): void {
    expect((new EmailNotificationSender)->channel())->toBe(NotificationChannel::EMAIL);
});

it('does not register the Resend package webhook route', function (): void {
    expect(collect(app('router')->getRoutes()->getRoutes())->contains(fn ($r) => str_contains($r->uri(), 'resend')))->toBeFalse();
});

it('order-updated shows the new items total and total paid, never the internal settlement note', function (): void {
    $m = new OrderUpdated(mailData(['totalMinor' => 2600, 'amountMinor' => 5400, 'settlementNote' => 'INTERNAL-SETTLEMENT']));
    foreach ([$m->render(), view($m->content()->text, $m->content()->with)->render()] as $out) {
        expect($out)->toContain('VH-2026-000123', 'Shea Pomade', 'GHS 54.00', 'GHS 26.00', 'https://shop.test/track-order')
            ->not->toContain('INTERNAL-SETTLEMENT');
    }
    expect($m->envelope()->subject)->toBe('Order VH-2026-000123 has been updated');
});

it('gives each order-journey email a warm subject with the order number', function (string $class, string $subject): void {
    expect((new $class(mailData()))->envelope()->subject)->toBe($subject);
})->with([
    [OrderConfirmation::class, 'Payment received! Order VH-2026-000123 is confirmed 🎉'],
    [ReadyForDispatch::class, 'Your order VH-2026-000123 is packed and ready 📦'],
    [Dispatched::class, 'Your order VH-2026-000123 is on its way 🚚'],
    [Delivered::class, 'Your order VH-2026-000123 has been delivered 💛'],
]);

it('greets by first name, shows progress at the right step and signs off from the team', function (string $class, string $current, array $copy): void {
    $m = new $class(mailData());
    $html = $m->render();
    $text = view($m->content()->text, $m->content()->with)->render();
    // Compare the visible copy: the HTML bolds the name and wraps phrases in markup.
    $visible = html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($html)), ENT_QUOTES);
    foreach ([$visible, $text] as $out) {
        expect($out)->toContain('Hi Ama,', 'Paid', 'Packed', 'On its way', 'Delivered', 'The Vidorck Hair Team', ...$copy)
            ->not->toContain('Hi Ama Mensah');
    }
    expect($html)->toContain('data-step="'.$current.'" data-state="current"');
    expect($text)->toContain('['.$current.']');
})->with([
    [OrderConfirmation::class, 'Paid', ['Thank you for shopping with Vidorck Hair', 'GHS 150.50']],
    [ReadyForDispatch::class, 'Packed', ['packed and is ready for dispatch', 'keep an eye on your phone']],
    [Dispatched::class, 'On its way', ['on its way to you', 'keep your phone nearby']],
    [Delivered::class, 'Delivered', ['marked as delivered', 'love your purchase']],
]);

it('only points to the help details when there are some', function (): void {
    config(['mail.reply_to.address' => '', 'services.notifications.support_phone' => '', 'services.notifications.support_whatsapp' => '']);
    $m = new Delivered(mailData());
    foreach ([strip_tags($m->render()), view($m->content()->text, $m->content()->with)->render()] as $out) {
        expect($out)->toContain('get in touch with us')->not->toContain('details below');
    }
});
