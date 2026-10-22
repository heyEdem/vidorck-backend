<?php

use App\Modules\Notifications\Exceptions\PermanentNotificationException;
use App\Modules\Notifications\Exceptions\TransientNotificationException;
use App\Modules\Notifications\Sms\ArkeselSmsProvider;
use App\Modules\Notifications\Sms\SmsOnlineGh\SdkSmsOnlineGhClient;
use App\Modules\Notifications\Sms\SmsOnlineGh\SmsOnlineGhClient;
use App\Modules\Notifications\Sms\SmsOnlineGh\SmsOnlineGhOutcome;
use App\Modules\Notifications\Sms\SmsOnlineGhSmsProvider;
use App\Modules\Notifications\Sms\SmsProvider;
use Illuminate\Support\Facades\Http;
use Zenoph\Notify\Request\RequestException;
use Zenoph\Notify\Request\SMSRequest;
use Zenoph\Notify\Response\APIResponse;
use Zenoph\Notify\Response\MessageResponse;

/** Fake seam: returns a canned outcome and records calls. No network. */
final class FakeSmsOnlineGhClient implements SmsOnlineGhClient
{
    /** @var list<array<int, string>> */
    public array $calls = [];

    public function __construct(public SmsOnlineGhOutcome $outcome) {}

    public function send(string $host, string $apiKey, string $sender, string $to, string $message): SmsOnlineGhOutcome
    {
        $this->calls[] = [$host, $apiKey, $sender, $to, $message];

        return $this->outcome;
    }
}

beforeEach(function (): void {
    config([
        'services.smsonlinegh.key' => 'secret-key', 'services.smsonlinegh.host' => 'api.smsonlinegh.test',
        'services.smsonlinegh.sender_id' => 'VIDORCK',
    ]);
    Http::preventStrayRequests();
});

function ghSend(SmsOnlineGhOutcome $o, string $to = '+233241234567'): array
{
    $fake = new FakeSmsOnlineGhClient($o);
    $result = (new SmsOnlineGhSmsProvider($fake))->send($to, 'Hi');

    return [$result, $fake];
}

function ghFail(SmsOnlineGhOutcome $o): ?Throwable
{
    try {
        ghSend($o);
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

it('sends one local-format-free recipient and returns the message id', function (): void {
    [$r, $fake] = ghSend(new SmsOnlineGhOutcome(200, 0, 2109, 'm-1'));

    expect($r->provider)->toBe('smsonlinegh')->and($r->messageId)->toBe('m-1');
    expect($fake->calls)->toBe([['api.smsonlinegh.test', 'secret-key', 'VIDORCK', '233241234567', 'Hi']]);
});

it('accepts success without an id', function (): void {
    expect(ghSend(new SmsOnlineGhOutcome(200, 0, 2107))[0]->messageId)->toBeNull();
});

it('maps permanent failures', function (SmsOnlineGhOutcome $o): void {
    expect(ghFail($o))->toBeInstanceOf(PermanentNotificationException::class);
})->with([
    'http 400' => new SmsOnlineGhOutcome(400), 'http 401' => new SmsOnlineGhOutcome(401),
    'http 403' => new SmsOnlineGhOutcome(403), 'http 422' => new SmsOnlineGhOutcome(422),
    'hs 1203' => new SmsOnlineGhOutcome(200, 1203), 'hs 1204' => new SmsOnlineGhOutcome(200, 1204),
    'hs 1205' => new SmsOnlineGhOutcome(200, 1205), 'hs 1401' => new SmsOnlineGhOutcome(200, 1401),
    'hs 1402' => new SmsOnlineGhOutcome(200, 1402), 'hs 1405' => new SmsOnlineGhOutcome(200, 1405),
    'hs 1408' => new SmsOnlineGhOutcome(200, 1408), 'hs 1409' => new SmsOnlineGhOutcome(200, 1409),
    'rejected destination 2119' => new SmsOnlineGhOutcome(200, 0, 2119),
    'insufficient credit 2121' => new SmsOnlineGhOutcome(200, 0, 2121),
    'rejected sender 2126' => new SmsOnlineGhOutcome(200, 0, 2126),
    'rejected sender numeric 2127' => new SmsOnlineGhOutcome(200, 0, 2127),
    'unregistered sender 2128' => new SmsOnlineGhOutcome(200, 0, 2128),
    'credit hold 2140' => new SmsOnlineGhOutcome(200, 0, 2140),
]);

it('maps transient failures', function (SmsOnlineGhOutcome $o): void {
    expect(ghFail($o))->toBeInstanceOf(TransientNotificationException::class);
})->with([
    'connect or timeout' => new SmsOnlineGhOutcome(0), 'http 500' => new SmsOnlineGhOutcome(500),
    'http 503' => new SmsOnlineGhOutcome(503), 'http 404' => new SmsOnlineGhOutcome(404),
    'hs 1403' => new SmsOnlineGhOutcome(200, 1403), 'hs 1407' => new SmsOnlineGhOutcome(200, 1407),
    'hs 1404 unknown' => new SmsOnlineGhOutcome(200, 1404), 'hs unrecognised' => new SmsOnlineGhOutcome(200, 9999),
    'no handshake' => new SmsOnlineGhOutcome(200),
    'unknown status' => new SmsOnlineGhOutcome(200, 0, 2101), 'no status' => new SmsOnlineGhOutcome(200, 0),
    'rejected generic' => new SmsOnlineGhOutcome(200, 0, 2113), 'unavailable conn' => new SmsOnlineGhOutcome(200, 0, 2134),
]);

it('fails a rejected destination even though the handshake is OK', function (): void {
    $e = ghFail(new SmsOnlineGhOutcome(200, 0, 2126));
    expect($e)->toBeInstanceOf(PermanentNotificationException::class)->and($e->getMessage())->toContain('2126');
});

it('rejects a malformed recipient permanently without calling the provider', function (): void {
    $fake = new FakeSmsOnlineGhClient(new SmsOnlineGhOutcome(200, 0, 2109));
    expect(fn () => (new SmsOnlineGhSmsProvider($fake))->send('not-a-number', 'Hi'))->toThrow(PermanentNotificationException::class);
    expect($fake->calls)->toBe([]);
});

it('fails transiently when the api key is missing, without calling the provider', function (): void {
    config(['services.smsonlinegh.key' => null]);
    $fake = new FakeSmsOnlineGhClient(new SmsOnlineGhOutcome(200, 0, 2109));
    expect(fn () => (new SmsOnlineGhSmsProvider($fake))->send('+233241234567', 'Hi'))->toThrow(TransientNotificationException::class);
    expect($fake->calls)->toBe([]);
});

it('never leaks provider text, numbers, or the key into exception messages', function (): void {
    foreach ([new SmsOnlineGhOutcome(401), new SmsOnlineGhOutcome(0), new SmsOnlineGhOutcome(200, 1203), new SmsOnlineGhOutcome(200, 0, 2121)] as $o) {
        $e = ghFail($o);
        expect($e->getMessage())->not->toContain('241234567')->not->toContain('secret-key')->not->toContain('Unauthorised')
            ->and($e->getMessage())->toMatch('/^SMSOnlineGH [a-z ]+ \((HTTP|handshake|status) ?\d*\)\.$/');
    }
});

// --- real client against a stubbed SDK request (no network) ---

function ghXml(int $handshake, ?int $status = null, ?string $id = null): string
{
    $dest = $status === null ? '' : '<destinations><item><to>233241234567</to>'.($id ? "<id>{$id}</id>" : '')."<status><id>{$status}</id></status></item></destinations>";

    return "<response><handshake><id>{$handshake}</id></handshake><data><category>sms</category><batch>b1</batch>{$dest}</data></response>";
}

/** @param list<SMSRequest> $made */
function ghClient(array &$made, ?string $xml = null, ?Throwable $throw = null): SdkSmsOnlineGhClient
{
    return new SdkSmsOnlineGhClient(function () use (&$made, $xml, $throw): SMSRequest {
        return $made[] = new class($xml, $throw) extends SMSRequest
        {
            public function __construct(private ?string $xml, private ?Throwable $throw)
            {
                parent::__construct();
            }

            public function submit(): MessageResponse
            {
                if ($this->throw) {
                    throw $this->throw;
                }
                $arr = [200, 'application/xml', $this->xml];

                return MessageResponse::create(APIResponse::createFromArray($arr));
            }
        };
    });
}

it('parses status and message id from the SDK response', function (): void {
    $made = [];
    $o = ghClient($made, ghXml(0, 2109, 'srv-9'))->send('api.x', 'k', 'VIDORCK', '233241234567', 'Hi');

    expect($o)->toEqual(new SmsOnlineGhOutcome(200, 0, 2109, 'srv-9'));
    expect($made[0]->getSender())->toBe('VIDORCK')->and($made[0]->getMessage())->toBe('Hi')
        ->and($made[0]->getComposer()->getDestinationsCount())->toBe(1);
});

it('reads a rejected destination returned with handshake OK, even without an id', function (): void {
    $made = [];
    $o = ghClient($made, ghXml(0, 2128))->send('api.x', 'k', 'S', '233241234567', 'Hi');

    expect($o->handshake)->toBe(0)->and($o->statusId)->toBe(2128)->and($o->messageId)->toBeNull();
});

it('builds a fresh SMSRequest per send so destinations never accumulate', function (): void {
    $made = [];
    $client = ghClient($made, ghXml(0, 2109, 'a'));
    $client->send('api.x', 'k', 'S', '233241234567', 'One');
    $client->send('api.x', 'k', 'S', '233200000001', 'Two');

    expect($made)->toHaveCount(2)->and($made[0])->not->toBe($made[1])
        ->and($made[0]->getComposer()->getDestinationsCount())->toBe(1)
        ->and($made[1]->getComposer()->getDestinationsCount())->toBe(1);
});

it('turns SDK exceptions into codes', function (Throwable $ex, SmsOnlineGhOutcome $expected): void {
    $made = [];
    expect(ghClient($made, null, $ex)->send('api.x', 'k', 'S', '233241234567', 'Hi'))->toEqual($expected);
})->with([
    'handshake' => [new RequestException('Request handshake failure! User authentication failed.', 1203), new SmsOnlineGhOutcome(200, 1203)],
    'unauthorised' => [new Exception('Unauthorised request.'), new SmsOnlineGhOutcome(401)],
    'forbidden' => [new Exception('Forbidden request.'), new SmsOnlineGhOutcome(403)],
    'bad request' => [new Exception('Bad request.'), new SmsOnlineGhOutcome(400)],
    'internal' => [new Exception('Internal server error.'), new SmsOnlineGhOutcome(500)],
    'unknown http' => [new Exception('Unknown request error <502>.'), new SmsOnlineGhOutcome(502)],
    'connect failure' => [new Exception('Unknown request error <0>.'), new SmsOnlineGhOutcome(0)],
    'empty body' => [new Exception('Request submit error: <200>'), new SmsOnlineGhOutcome(0)],
]);

it('rejects a handshake failure from the real response parser', function (): void {
    $made = [];
    $o = ghClient($made, ghXml(1203))->send('api.x', 'k', 'S', '233241234567', 'Hi');
    expect($o)->toEqual(new SmsOnlineGhOutcome(200, 1203));
});

it('binds the smsonlinegh driver and keeps arkesel', function (): void {
    config(['services.sms.driver' => 'smsonlinegh']);
    app()->forgetInstance(SmsProvider::class);
    expect(app(SmsProvider::class))->toBeInstanceOf(SmsOnlineGhSmsProvider::class);
    expect(app(SmsOnlineGhClient::class))->toBeInstanceOf(SdkSmsOnlineGhClient::class);

    config(['services.sms.driver' => 'arkesel']);
    app()->forgetInstance(SmsProvider::class);
    expect(app(SmsProvider::class))->toBeInstanceOf(ArkeselSmsProvider::class);
});

it('tests default to the log driver and empty provider keys', function (): void {
    expect(env('SMS_DRIVER'))->toBe('log')->and(env('SMSONLINEGH_API_KEY'))->toBe('')->and(env('RESEND_API_KEY'))->toBe('');
});
