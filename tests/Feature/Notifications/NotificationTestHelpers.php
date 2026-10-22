<?php

use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Staff\Enums\StaffRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function ntAttempt(?string $email = 'ama@example.com'): CheckoutAttempt
{
    $publicId = (string) Str::ulid();
    $reference = 'nt-'.Str::random(15);

    return CheckoutAttempt::query()->create([
        'public_id' => $publicId, 'paystack_reference' => $reference,
        'customer_snapshot_json' => ['fullName' => 'Ama Mensah', 'phoneE164' => '+233241234567', 'phoneDisplay' => '0241234567', 'email' => $email],
        'delivery_snapshot_json' => ['area' => 'Osu', 'address' => '1 Main St', 'region' => 'Greater Accra'],
        'items_snapshot_json' => [['lineType' => 'PRODUCT', 'name' => 'Oil', 'size' => '100ml', 'productCode' => 'OIL', 'quantity' => 2, 'unitPriceMinor' => 500, 'lineTotalMinor' => 1000]],
        'amount_minor' => 1000, 'currency' => 'GHS', 'status' => 'PENDING', 'expires_at' => now()->subDay(), 'verified_at' => now(),
        'verification_snapshot_json' => ['status' => 'success', 'reference' => $reference, 'currency' => 'GHS', 'amount' => 1000, 'metadata' => ['attemptId' => $publicId]],
    ]);
}

/** @return list<object> unserialised SendNotification jobs */
function ntCommands(): array
{
    return DB::table('jobs')->get()->map(fn ($j) => unserialize(decrypt(json_decode($j->payload, true)['data']['command'])))->all();
}

function ntAdmin(): StaffUser
{
    return StaffUser::query()->where('role', StaffRole::ADMIN->value)->first()
        ?? StaffUser::create(['name' => 'A', 'email' => 'admin@example.com', 'password' => 'Long-Staff-Password9!', 'role' => StaffRole::ADMIN, 'is_active' => true]);
}
