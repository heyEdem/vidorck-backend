<?php

namespace App\Modules\Staff\Console;

use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use App\Modules\Staff\Support\StaffPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CreateAdminCommand extends Command
{
    protected $signature = 'staff:create-admin
        {--force : Allow an additional Admin account}
        {--from-env : Read credentials from BOOTSTRAP_ADMIN_* and skip if an Admin exists}';

    protected $description = 'Create an Admin using interactive credentials; never replaces an existing account';

    public function handle(): int
    {
        $fromEnv = (bool) $this->option('from-env');

        if (StaffUser::where('role', StaffRole::ADMIN)->exists()) {
            // Boot-time bootstrap is idempotent and never adds a second Admin.
            if ($fromEnv) {
                $this->info('An Admin already exists; skipping bootstrap.');

                return self::SUCCESS;
            }
            if (! $this->option('force')) {
                $this->error('An Admin already exists. Use --force to create an additional Admin.');

                return self::FAILURE;
            }
        }

        $input = $fromEnv ? (array) config('auth.bootstrap_admin') : [
            'name' => $this->ask('Name'),
            'email' => $this->ask('Email'),
            'password' => $this->secret('Password'),
        ];
        $input['email'] = mb_strtolower(trim((string) $input['email']));
        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:staff_users,email'],
            'password' => StaffPassword::rules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($validator): void {
            $admin = StaffUser::create([...$validator->validated(), 'role' => StaffRole::ADMIN]);
            app(AuditLogger::class)->record(null, 'staff.bootstrap', $admin, after: $admin->only(['name', 'email', 'role', 'is_active']));
        });
        $this->info('Admin created.');

        return self::SUCCESS;
    }
}
