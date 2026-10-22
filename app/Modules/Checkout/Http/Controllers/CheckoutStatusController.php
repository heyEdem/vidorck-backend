<?php

namespace App\Modules\Checkout\Http\Controllers;

use App\Modules\Checkout\Actions\CheckCheckoutStatus;
use App\Modules\Checkout\Models\CheckoutAttempt;
use Illuminate\Http\JsonResponse;

final class CheckoutStatusController
{
    public function __invoke(string $reference, CheckCheckoutStatus $checkStatus): JsonResponse
    {
        $attempt = CheckoutAttempt::query()->where('paystack_reference', $reference)->first();
        if ($attempt === null) {
            return response()->json(['message' => 'Not found.', 'code' => 'NOT_FOUND', 'errors' => (object) []], 404);
        }

        return response()->json($checkStatus->execute($attempt));
    }
}
