<?php

namespace App\Modules\Checkout\Http\Resources\Admin;

use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Support\Http\ApiResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** @mixin CheckoutAttempt */
final class ReconciliationAttemptResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attemptId' => $this->public_id,
            'reference' => $this->paystack_reference,
            'status' => CheckoutAttemptStatus::from($this->getRawOriginal('status'))->value,
            'amountMinor' => $this->amount_minor,
            'currency' => $this->currency,
            'expiresAt' => $this->timestamp('expires_at'),
            'createdAt' => $this->timestamp('created_at'),
            'verifiedAt' => $this->timestamp('verified_at'),
            'lastVerifiedAt' => $this->timestamp('last_verified_at'),
        ];
    }

    private function timestamp(string $key): ?string
    {
        $value = $this->getRawOriginal($key);

        return is_string($value) ? CarbonImmutable::parse($value)->toIso8601String() : null;
    }
}
