<?php

namespace App\Modules\Checkout\Http\Controllers\Public;

use App\Modules\Checkout\Actions\RetryCheckout;
use Illuminate\Http\JsonResponse;

final class CheckoutRetryController
{
    public function __invoke(string $reference, RetryCheckout $retry): JsonResponse
    {
        return response()->json($retry->execute($reference), 201);
    }
}
