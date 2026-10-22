<?php

use App\Modules\Complaints\Models\Complaint;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('serializes competing complaint claims into a coherent projection and event history', function (): void {
    $directory = sys_get_temp_dir().'/vhi-complaint-race-'.Str::random(16);
    mkdir($directory, 0700, true);
    $database = $directory.'/complaints.sqlite';
    touch($database);
    $previousDefault = config('database.default');
    $previousDatabase = config('database.connections.sqlite.database');
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database]);
    DB::purge('sqlite');

    try {
        DB::connection('sqlite')->getPdo();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $first = StaffUser::query()->create(['name' => 'Agent A', 'email' => 'a@example.com', 'password' => 'Long-Staff-Password9!', 'role' => StaffRole::CUSTOMER_SERVICE]);
        $second = StaffUser::query()->create(['name' => 'Agent B', 'email' => 'b@example.com', 'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN]);
        $complaint = Complaint::query()->create(['reference' => 'C-000001', 'name' => 'Ama Mensah', 'topic' => 'OTHER', 'message' => 'Help', 'contact_method' => 'CALL', 'phone_e164' => '+233241234567', 'status' => 'NEW']);
        DB::table('complaint_events')->insert(['complaint_id' => $complaint->id, 'staff_id' => null, 'type' => 'SUBMITTED', 'to_status' => 'NEW', 'created_at' => now()]);

        $script = base_path('tests/Fixtures/complaints/concurrent-claim-worker.php');
        $processes = [
            new Process([PHP_BINARY, $script, $database, (string) $complaint->id, (string) $first->id, $directory, 'a'], base_path(), ['APP_ENV' => 'testing']),
            new Process([PHP_BINARY, $script, $database, (string) $complaint->id, (string) $second->id, $directory, 'b'], base_path(), ['APP_ENV' => 'testing']),
        ];
        foreach ($processes as $process) {
            $process->setTimeout(20);
            $process->start();
        }
        $deadline = microtime(true) + 10;
        while ((! is_file($directory.'/a.ready') || ! is_file($directory.'/b.ready')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(is_file($directory.'/a.ready') && is_file($directory.'/b.ready'))->toBeTrue();
        touch($directory.'/release');
        $outputs = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
            $outputs[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        $fresh = $complaint->fresh();
        $events = DB::table('complaint_events')->where('complaint_id', $complaint->id)->orderBy('id')->get();
        expect($fresh->status->value)->toBe('IN_PROGRESS')
            ->and($fresh->assigned_staff_id)->toBeIn([$first->id, $second->id])
            ->and(collect($outputs)->pluck('status')->unique()->all())->toBe(['IN_PROGRESS'])
            ->and(collect($outputs)->pluck('assignedStaffId')->all())->toContain($fresh->assigned_staff_id)
            ->and($events->pluck('type')->all())->toBe(['SUBMITTED', 'CLAIMED', 'STATUS_CHANGED', 'CLAIMED'])
            ->and($events->where('type', 'STATUS_CHANGED')->first()->from_status)->toBe('NEW')
            ->and($events->where('type', 'STATUS_CHANGED')->first()->to_status)->toBe('IN_PROGRESS')
            ->and($events->where('type', 'CLAIMED')->last()->staff_id)->toBe($fresh->assigned_staff_id);
    } finally {
        DB::disconnect('sqlite');
        DB::purge('sqlite');
        config(['database.default' => $previousDefault, 'database.connections.sqlite.database' => $previousDatabase]);
        foreach (glob($directory.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});
