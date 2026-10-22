<?php

use App\Modules\Complaints\Actions\ClaimComplaint;
use App\Modules\Complaints\Models\Complaint;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[$script, $databasePath, $complaintId, $staffId, $barrierDirectory, $worker] = $argv + [null, null, null, null, null, null];
if (! is_string($databasePath) || ! is_file($databasePath) || ! ctype_digit((string) $complaintId) || ! ctype_digit((string) $staffId)
    || ! is_string($barrierDirectory) || ! is_dir($barrierDirectory) || ! in_array($worker, ['a', 'b'], true)) {
    fwrite(STDERR, "Invalid worker arguments.\n");
    exit(2);
}

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $databasePath]);
app(DatabaseManager::class)->purge('sqlite');
file_put_contents($barrierDirectory.'/'.$worker.'.ready', 'ready', LOCK_EX);
$deadline = microtime(true) + 10;
while (! is_file($barrierDirectory.'/release')) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for claim release.');
    }
    usleep(10000);
}

$complaint = Complaint::query()->findOrFail((int) $complaintId);
$staff = StaffUser::query()->findOrFail((int) $staffId);
$result = app(ClaimComplaint::class)->execute($complaint, $staff);
echo json_encode(['status' => $result->status->value, 'assignedStaffId' => $result->assigned_staff_id], JSON_THROW_ON_ERROR);
