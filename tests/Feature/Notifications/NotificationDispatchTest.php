<?php

use App\Modules\Notifications\Actions\CountFailedNotifications;
use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\Enums\NotificationAttemptEvent as Event;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Exceptions\PermanentNotificationException;
use App\Modules\Notifications\Jobs\SendNotification;
use App\Modules\Notifications\Mail\EmailNotificationSender;
use App\Modules\Notifications\Models\NotificationAttempt;
use App\Modules\Notifications\Models\NotificationLog;
use App\Modules\Notifications\NotificationDispatcher;
use App\Modules\Notifications\Repositories\NotificationAttemptRepository;
use App\Modules\Notifications\Sms\SmsNotificationSender;
use App\Modules\Notifications\Support\NotificationPayload;
use App\Modules\Notifications\Support\SendResult;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DatabaseMigrations;

uses(DatabaseMigrations::class);

final class FakeSender implements NotificationSender
{
    public int $calls = 0;

    public ?Throwable $throw = null;

    public function __construct(private NotificationChannel $channel) {}

    public function channel(): NotificationChannel
    {
        return $this->channel;
    }

    public function send(string $recipient, NotificationPayload $payload): SendResult
    {
        $this->calls++;
        if ($this->throw) {
            throw $this->throw;
        }

        return new SendResult('fake', 'msg-1');
    }
}

function fakeSender(NotificationChannel $channel = NotificationChannel::EMAIL): FakeSender
{
    $sender = new FakeSender($channel);
    // Replace the real tagged sender for this channel so only the fake handles it.
    app()->instance($channel === NotificationChannel::SMS ? SmsNotificationSender::class : EmailNotificationSender::class, $sender);

    return $sender;
}

function payload(): NotificationPayload
{
    return new NotificationPayload('order-confirmation', ['orderNumber' => 'VHI-1'], 'order', 1);
}

function dispatchEmail(string $key = 'k1', string $to = 'Ada@Example.com'): bool
{
    return app(NotificationDispatcher::class)->once($key, NotificationChannel::EMAIL, $to, payload());
}

function jobRow(): SendNotification
{
    return new SendNotification(NotificationLog::query()->firstOrFail()->id, NotificationChannel::EMAIL, 'ada@example.com', payload());
}

function runJob(SendNotification $job): void
{
    app()->call([$job, 'handle']);
}

beforeEach(function () {
    app()->detectEnvironment(fn () => 'staging'); // allowlists apply in staging only
    config(['services.notifications.mail_allowed_recipients' => ['ada@example.com'], 'services.sms.allowed_recipients' => ['0244123456']]);
});

it('queues one job for a duplicate key', function () {
    expect(dispatchEmail())->toBeTrue()->and(dispatchEmail())->toBeFalse();
    expect(NotificationLog::count())->toBe(1)->and(DB::table('jobs')->count())->toBe(1);
});

it('leaves no log and no job when the caller transaction rolls back', function () {
    expect(config('queue.default'))->toBe('sync'); // dispatcher must not depend on the default queue
    try {
        DB::transaction(function () {
            dispatchEmail();
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }
    expect(NotificationLog::count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
    DB::transaction(fn () => dispatchEmail());
    expect(NotificationLog::count())->toBe(1)->and(DB::table('jobs')->count())->toBe(1);
});

it('separates recipients and channels by key', function () {
    $d = app(NotificationDispatcher::class);
    $a = NotificationDispatcher::key('order:1', NotificationChannel::EMAIL, 'a@example.com');
    $b = NotificationDispatcher::key('order:1', NotificationChannel::EMAIL, 'b@example.com');
    $s = NotificationDispatcher::key('order:1', NotificationChannel::SMS, '0244123456');
    expect($a)->not->toBe($b)->and($a)->not->toBe($s);
    expect($d->once($a, NotificationChannel::EMAIL, 'a@example.com', payload()))->toBeTrue()
        ->and($d->once($b, NotificationChannel::EMAIL, 'b@example.com', payload()))->toBeTrue()
        ->and($d->once($s, NotificationChannel::SMS, '0244123456', payload()))->toBeTrue()
        ->and($d->once($a, NotificationChannel::EMAIL, ' A@Example.com ', payload()))->toBeFalse();
    expect(DB::table('jobs')->count())->toBe(3);
});

it('stores no raw recipient, body or provider response', function () {
    dispatchEmail();
    $log = NotificationLog::firstOrFail();
    expect($log->recipient_masked)->toBe('a***@example.com')
        ->and($log->recipient_hash)->toBe(hash('sha256', 'ada@example.com'));
    $dump = json_encode(DB::table('notification_logs')->get()).json_encode(DB::table('notification_attempts')->get());
    expect(strtolower($dump))->not->toContain('ada@example.com');
    $jobPayload = (string) DB::table('jobs')->value('payload');
    expect($jobPayload)->not->toContain('ada@example.com')->not->toContain('VHI-1'); // encrypted
});

it('rejects update, delete and truncate on both tables', function () {
    fakeSender();
    dispatchEmail();
    runJob(jobRow());
    $log = NotificationLog::firstOrFail();
    $attempt = NotificationAttempt::firstOrFail();
    foreach ([
        fn () => DB::table('notification_logs')->update(['template' => 'x']),
        fn () => DB::table('notification_logs')->delete(),
        fn () => DB::table('notification_logs')->truncate(),
        fn () => DB::table('notification_attempts')->update(['error_code' => 'x']),
        fn () => DB::table('notification_attempts')->delete(),
        fn () => DB::table('notification_attempts')->truncate(),
    ] as $mutation) {
        expect($mutation)->toThrow(QueryException::class);
    }
    expect(fn () => $log->update(['template' => 'x']))->toThrow(LogicException::class);
    expect(fn () => $attempt->delete())->toThrow(LogicException::class);
});

it('sends once and records SENT; a second run is a terminal no-op', function () {
    $sender = fakeSender();
    dispatchEmail();
    runJob(jobRow());
    runJob(jobRow());
    expect($sender->calls)->toBe(1);
    expect(NotificationAttempt::orderBy('id')->pluck('event_type')->map->value->all())->toBe(['STARTED', 'SENT']);
});

it('does not send when another worker already holds the claim', function () {
    $sender = fakeSender();
    dispatchEmail();
    $id = NotificationLog::firstOrFail()->id;
    app(NotificationAttemptRepository::class)->append($id, 1, Event::STARTED);
    runJob(jobRow());
    expect($sender->calls)->toBe(0);
    // The unique (log, number, event) row is the claim itself.
    expect(app(NotificationAttemptRepository::class)->append($id, 1, Event::STARTED))->toBeNull();
    // A stale claim (crashed worker) can be taken over by the next attempt number.
    $this->travel(61)->seconds();
    runJob(jobRow());
    expect($sender->calls)->toBe(1)->and(NotificationAttempt::where('attempt_number', 2)->count())->toBe(2);
});

it('treats SKIPPED and EXHAUSTED as terminal', function () {
    $sender = fakeSender();
    dispatchEmail();
    $id = NotificationLog::firstOrFail()->id;
    app(NotificationAttemptRepository::class)->append($id, 1, Event::EXHAUSTED);
    runJob(jobRow());
    expect($sender->calls)->toBe(0)->and(NotificationAttempt::count())->toBe(1);
});

it('records FAILED on a transient error, retries, and clears the failed count on success', function () {
    $sender = fakeSender();
    $sender->throw = new RuntimeException('timeout contacting ada@example.com');
    dispatchEmail();
    expect(fn () => runJob(jobRow()))->toThrow(RuntimeException::class);
    expect(app(CountFailedNotifications::class)->execute())->toBe(1);
    $summary = NotificationAttempt::where('event_type', 'FAILED')->value('error_summary');
    expect($summary)->not->toContain('ada@example.com');
    $this->travel(2)->minutes();
    $sender->throw = null;
    runJob(jobRow());
    expect(app(CountFailedNotifications::class)->execute())->toBe(0);
});

it('fails permanently at once and failed() appends EXHAUSTED only once', function () {
    $sender = fakeSender();
    $sender->throw = new PermanentNotificationException('invalid recipient');
    dispatchEmail();
    $job = jobRow();
    runJob($job);
    $job->failed(new RuntimeException('again'));
    $job->failed(new RuntimeException('again'));
    expect($sender->calls)->toBe(1)
        ->and(NotificationAttempt::where('event_type', 'EXHAUSTED')->count())->toBe(1)
        ->and(app(CountFailedNotifications::class)->execute())->toBe(1);
});

it('failed() after exhausted retries is idempotent and not applied after SENT', function () {
    fakeSender();
    dispatchEmail();
    $job = jobRow();
    $job->failed(new RuntimeException('x'));
    $job->failed(new RuntimeException('x'));
    expect(NotificationAttempt::where('event_type', 'EXHAUSTED')->count())->toBe(1);

    dispatchEmail('k2', 'ada@example.com');
    $log2 = NotificationLog::where('idempotency_key', 'k2')->firstOrFail();
    $j2 = new SendNotification($log2->id, NotificationChannel::EMAIL, 'ada@example.com', payload());
    runJob($j2);
    $j2->failed(new RuntimeException('late'));
    expect(NotificationAttempt::where('notification_log_id', $log2->id)->where('event_type', 'EXHAUSTED')->count())->toBe(0);
});

it('has the required job settings and is encrypted', function () {
    $job = jobRow2();
    expect($job->tries)->toBe(4)->and($job->timeout)->toBe(30)->and($job->backoff())->toBe([60, 300, 900])
        ->and($job)->toBeInstanceOf(ShouldBeEncrypted::class);
});

function jobRow2(): SendNotification
{
    return new SendNotification(1, NotificationChannel::EMAIL, 'a@example.com', payload());
}

it('suppresses non-allowlisted recipients outside production with a SKIPPED attempt', function () {
    $sender = fakeSender();
    $sender->throw = new RuntimeException('must not be called');
    app(NotificationDispatcher::class)->once('k9', NotificationChannel::EMAIL, 'stranger@example.com', payload());
    runJob(new SendNotification(NotificationLog::firstOrFail()->id, NotificationChannel::EMAIL, 'stranger@example.com', payload()));
    expect($sender->calls)->toBe(0)
        ->and(NotificationAttempt::orderBy('id')->pluck('event_type')->map->value->all())->toBe(['STARTED', 'SKIPPED']);
});

it('allows allowlisted SMS numbers in any format and has no restriction in production', function () {
    $sms = fakeSender(NotificationChannel::SMS);
    app(NotificationDispatcher::class)->once('s1', NotificationChannel::SMS, '+233244123456', payload());
    runJob(new SendNotification(NotificationLog::firstOrFail()->id, NotificationChannel::SMS, '+233244123456', payload()));
    expect($sms->calls)->toBe(1);

    app()->detectEnvironment(fn () => 'production');
    $email = fakeSender();
    app(NotificationDispatcher::class)->once('p1', NotificationChannel::EMAIL, 'anyone@example.com', payload());
    runJob(new SendNotification(NotificationLog::where('idempotency_key', 'p1')->firstOrFail()->id, NotificationChannel::EMAIL, 'anyone@example.com', payload()));
    expect($email->calls)->toBe(1);
    app()->detectEnvironment(fn () => 'testing');
    $local = fakeSender();
    app()->detectEnvironment(fn () => 'local');
    app(NotificationDispatcher::class)->once('l1', NotificationChannel::EMAIL, 'stranger@example.com', payload());
    runJob(new SendNotification(NotificationLog::where('idempotency_key', 'l1')->firstOrFail()->id, NotificationChannel::EMAIL, 'stranger@example.com', payload()));
    expect($local->calls)->toBe(1);
    app()->detectEnvironment(fn () => 'testing');
});

it('releases (not drops) a job that finds a fresh in-flight claim, and the stale threshold is below retry_after', function () {
    fakeSender();
    dispatchEmail();
    app(NotificationAttemptRepository::class)->append(NotificationLog::firstOrFail()->id, 1, Event::STARTED);
    $job = jobRow();
    $fake = new SyncJob(app(), '{}', 'sync', 'default');
    $job->setJob($fake);
    runJob($job);
    expect($fake->isReleased())->toBeTrue()->and($fake->isDeleted())->toBeFalse()
        ->and(SendNotification::IN_FLIGHT_SECONDS)->toBeLessThan(config('queue.connections.database.retry_after'));
});
