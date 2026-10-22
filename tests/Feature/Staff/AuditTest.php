<?php

use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('records audit metadata and recursively removes secrets', function () {
    request()->attributes->set('request_id', 'audit-request');
    $log = app(AuditLogger::class)->record(null, 'test', null, [], [
        'name' => 'Safe', 'password' => 'hidden', 'nested' => [
            'accessToken' => 'hidden', 'API_KEY' => 'hidden', 'cvv' => '123',
            'privateKey' => 'hidden', 'pan' => 'hidden', 'pin' => 'hidden',
            'paymentData' => ['anything' => 'hidden'], 'payload' => ['anything' => 'hidden'],
            'payment_payload' => ['anything' => 'hidden'], 'isActive' => true,
        ], 'items' => [['client_secret' => 'hidden', 'status' => 'ok']],
    ]);
    expect($log->request_id)->toBe('audit-request')
        ->and($log->after)->toBe(['name' => 'Safe', 'nested' => ['isActive' => true], 'items' => [['status' => 'ok']]])
        ->and($log->actor_type)->toBe('SYSTEM');
});

it('prevents audit model updates', function () {
    $log = app(AuditLogger::class)->record(null, 'test');
    $log->update(['action' => 'tampered']);
})->throws(LogicException::class);

it('prevents audit model deletion', function () {
    app(AuditLogger::class)->record(null, 'test')->delete();
})->throws(LogicException::class);

it('prevents bulk audit tampering at the database layer', function (string $operation) {
    app(AuditLogger::class)->record(null, 'test');
    try {
        if ($operation === 'update') {
            DB::table('audit_logs')->update(['action' => 'tampered']);
        } else {
            AuditLog::query()->delete();
        }
        test()->fail('Audit modification was allowed.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('append-only');
    }
    expect(AuditLog::sole()->action)->toBe('test');
})->with(['update', 'delete']);
