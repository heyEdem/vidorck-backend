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
    protected $signature = 'staff:create-admin {--force : Allow an additional Admin account}';

    protected $description = 'Create an Admin using interactive credentials; never replaces an existing account';

    public function handle(): int
    {
        if (StaffUser::where('role', StaffRole::ADMIN)->exists() && ! $this->option('force')) {
            $this->error('An Admin already exists. Use --force to create an additional Admin.');

            return self::FAILURE;
        }

        $input = [
            'name' => $this->ask('Name'),
            'email' => mb_strtolower(trim((string) $this->ask('Email'))),
            'password' => $this->secret('Password'),
        ];
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
