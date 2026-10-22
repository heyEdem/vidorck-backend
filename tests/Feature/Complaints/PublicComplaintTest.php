<?php

use App\Modules\Notifications\Actions\CountFailedNotifications;
use App\Modules\Notifications\Exceptions\TransientNotificationException;
use App\Modules\Notifications\Jobs\SendNotification;
use App\Modules\Notifications\Sms\SmsMessage;
use App\Modules\Notifications\Sms\SmsProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::clear('contact-submissions');
});

function complaintBody(array $over = []): array
{
    return array_merge(['name' => 'Ama Mensah', 'topic' => 'ORDER_PROBLEM', 'orderNumber' => 'VH-2026-0001', 'message' => 'Please help with my order.', 'contactMethod' => 'CALL', 'phone' => '0241234567'], $over);
}

function complaintJobs(): int
{
    return DB::table('jobs')->count();
}

it('persists complaint, reference and initial immutable event and queues one chosen-channel acknowledgement', function (): void {
    $this->postJson('/api/v1/complaints', complaintBody())
        ->assertCreated()->assertExactJson(['data' => ['reference' => 'C-000001', 'status' => 'NEW']]);

    expect(DB::table('complaints')->count())->toBe(1)
        ->and(DB::table('complaint_events')->first()->type)->toBe('SUBMITTED')
        ->and(DB::table('complaint_events')->first()->to_status)->toBe('NEW')
        ->and(DB::table('complaints')->first()->phone_e164)->toBe('+233241234567')
        ->and(complaintJobs())->toBe(1);
    $command = unserialize(decrypt(json_decode(DB::table('jobs')->first()->payload, true)['data']['command']));
    expect($command)->toBeInstanceOf(SendNotification::class)
        ->and($command->payload->template)->toBe('complaint-received')
        ->and($command->payload->sourceType)->toBe('complaint')
        ->and($command->payload->sourceId)->toBe(1)
        ->and($command->payload->data)->toBe(['reference' => 'C-000001']);
});

it('requires contact details for the chosen method and emails only when EMAIL is selected', function (): void {
    $this->postJson('/api/v1/complaints', complaintBody(['contactMethod' => 'CALL', 'phone' => null]))->assertUnprocessable();
    $this->postJson('/api/v1/complaints', complaintBody(['contactMethod' => 'WHATSAPP', 'phone' => '0241234567']))->assertCreated();
    $this->postJson('/api/v1/complaints', complaintBody(['contactMethod' => 'EMAIL', 'phone' => null, 'email' => 'AMA@EXAMPLE.COM']))->assertCreated();

    $emailJob = DB::table('jobs')->orderByDesc('id')->first();
    $command = unserialize(decrypt(json_decode($emailJob->payload, true)['data']['command']));
    expect($command->channel->value)->toBe('EMAIL')
        ->and(DB::table('complaints')->orderByDesc('id')->first()->email)->toBe('ama@example.com');
});

it('renders the actual queued acknowledgement payload for CALL and WHATSAPP SMS', function (): void {
    foreach (['CALL', 'WHATSAPP'] as $method) {
        $this->postJson('/api/v1/complaints', complaintBody(['contactMethod' => $method]))->assertCreated();
        $job = DB::table('jobs')->orderByDesc('id')->first();
        $command = unserialize(decrypt(json_decode($job->payload, true)['data']['command']));

        expect($command)->toBeInstanceOf(SendNotification::class)
            ->and($command->channel->value)->toBe('SMS')
            ->and($command->payload->template)->toBe('complaint-received')
            ->and(SmsMessage::forPayload($command->payload))->toContain($command->payload->data['reference']);
    }
});

it('sends queued complaint SMS and records delivery failure without losing the submission', function (): void {
    config(['services.sms.driver' => 'arkesel', 'services.arkesel.key' => 'test-key', 'services.arkesel.base_url' => 'https://sms.test', 'services.arkesel.sender_id' => 'VIDORCK']);
    app()->forgetInstance(SmsProvider::class);
    Http::fake(['sms.test/*' => Http::sequence()
        ->push(['status' => 'success', 'data' => [['id' => 'sms-1']]])
        ->push(['status' => 'error', 'message' => 'provider unavailable'], 503)]);
    $this->postJson('/api/v1/complaints', complaintBody())->assertCreated();
    $successJob = unserialize(decrypt(json_decode(DB::table('jobs')->orderBy('id')->first()->payload, true)['data']['command']));
    app()->call([$successJob, 'handle']);
    expect(app(CountFailedNotifications::class)->execute())->toBe(0);
    Http::assertSent(fn ($request) => $request['message'] === SmsMessage::forPayload($successJob->payload));

    $this->postJson('/api/v1/complaints', complaintBody(['contactMethod' => 'WHATSAPP']))->assertCreated();
    $failureJob = unserialize(decrypt(json_decode(DB::table('jobs')->orderByDesc('id')->first()->payload, true)['data']['command']));
    expect(fn () => app()->call([$failureJob, 'handle']))->toThrow(TransientNotificationException::class)
        ->and(app(CountFailedNotifications::class)->execute())->toBe(1)
        ->and(DB::table('complaints')->count())->toBe(2);
});

it('retains optional phone and email details and lowercases email addresses', function (): void {
    $this->postJson('/api/v1/complaints', complaintBody(['email' => 'AMA@EXAMPLE.COM']))->assertCreated();
    $complaint = DB::table('complaints')->first();
    expect($complaint->phone_e164)->toBe('+233241234567')->and($complaint->email)->toBe('ama@example.com');
});

it('validates topic, contact method, control characters, name and message length', function (array $input): void {
    $this->postJson('/api/v1/complaints', complaintBody($input))->assertUnprocessable();
})->with([
    'bad topic' => [['topic' => 'REFUND']],
    'bad method' => [['contactMethod' => 'TEXT']],
    'invalid Ghana phone' => [['phone' => '12345']],
    'short name' => [['name' => 'A']],
    'long name' => [['name' => str_repeat('a', 101)]],
    'long message' => [['message' => str_repeat('a', 5001)]],
    'control in name' => [['name' => "Ama\r\nBcc: x@y.z"]],
    'null in message' => [['message' => "hi\0there"]],
]);

it('honeypot returns the normal acknowledgement without storing or notifying spam', function (): void {
    $this->postJson('/api/v1/complaints', ['website' => 'spam'])->assertOk()->assertJsonPath('data.status', 'RECEIVED');
    expect(DB::table('complaints')->count())->toBe(0)->and(complaintJobs())->toBe(0);
});

it('throttles complaint submissions using contact-submissions', function (): void {
    foreach (range(1, 3) as $_) {
        $this->postJson('/api/v1/complaints', complaintBody())->assertCreated();
    }
    $this->postJson('/api/v1/complaints', complaintBody())->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED')->assertHeader('Retry-After');
});

it('links a customer by phone before email and only links an order after contact verification', function (): void {
    $customerId = DB::table('customers')->insertGetId(['public_id' => '01JABCDEF0123456789ABCDEFGH', 'full_name' => 'Ama Mensah', 'phone_e164' => '+233241234567', 'phone_display' => '0241234567', 'email' => 'old@example.com', 'orders_count' => 0, 'lifetime_paid_minor' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('orders')->insert(['order_number' => 'VH-2026-0001', 'tracking_token' => str_repeat('a', 32), 'source' => 'ONLINE', 'customer_id' => $customerId, 'status' => 'PAID', 'subtotal_minor' => 100, 'total_paid_minor' => 100, 'currency' => 'GHS', 'payment_method' => 'MOMO', 'customer_snapshot_json' => json_encode(['phoneE164' => '+233241234567', 'email' => 'old@example.com']), 'delivery_snapshot_json' => '{}', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $this->postJson('/api/v1/complaints', complaintBody(['email' => 'NEW@EXAMPLE.COM']))->assertCreated();
    $linked = DB::table('complaints')->first();
    expect($linked->customer_id)->toBe($customerId)->and($linked->order_id)->not->toBeNull()->and($linked->order_number_entered)->toBe('VH-2026-0001');

    $this->postJson('/api/v1/complaints', complaintBody(['phone' => '0201234567', 'email' => 'new@example.com']))->assertCreated();
    $unverified = DB::table('complaints')->orderByDesc('id')->first();
    expect($unverified->customer_id)->toBeNull()->and($unverified->order_id)->toBeNull()->and($unverified->order_number_entered)->toBe('VH-2026-0001');
});

it('links a customer by lowercased email when no phone match exists', function (): void {
    $customerId = DB::table('customers')->insertGetId(['public_id' => '01JABCDEF0123456789ABCDEFGH', 'full_name' => 'Ama Mensah', 'phone_e164' => '+233201234567', 'phone_display' => '0201234567', 'email' => 'ama@example.com', 'orders_count' => 0, 'lifetime_paid_minor' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson('/api/v1/complaints', complaintBody(['phone' => '0241234567', 'email' => 'AMA@EXAMPLE.COM']))->assertCreated();
    expect(DB::table('complaints')->first()->customer_id)->toBe($customerId);
});

it('keeps the complaint committed when acknowledgement dispatch fails and removes the old relay endpoint', function (): void {
    Schema::drop('jobs');
    $this->postJson('/api/v1/complaints', complaintBody())->assertCreated();
    expect(DB::table('complaints')->count())->toBe(1)->and(DB::table('complaint_events')->count())->toBe(1);
    $this->postJson('/api/v1/contact', complaintBody())->assertNotFound();
});

it('enforces append-only complaint events in the database', function (): void {
    $this->postJson('/api/v1/complaints', complaintBody())->assertCreated();
    expect(fn () => DB::table('complaint_events')->where('id', 1)->update(['body' => 'edited']))->toThrow(QueryException::class);
    expect(fn () => DB::table('complaint_events')->where('id', 1)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('complaint_events')->delete())->toThrow(QueryException::class);
    expect(fn () => DB::statement('TRUNCATE TABLE complaint_events'))->toThrow(QueryException::class);
});

it('preserves complaint history and trigger enforcement when the migration is rolled back', function (): void {
    $this->postJson('/api/v1/complaints', complaintBody())->assertCreated();
    $migration = require database_path('migrations/2026_10_19_100000_create_complaints_tables.php');
    $migration->down();
    expect(Schema::hasTable('complaint_events'))->toBeTrue()
        ->and(DB::table('complaint_events')->count())->toBe(1);
    expect(fn () => DB::table('complaint_events')->delete())->toThrow(QueryException::class);
});
