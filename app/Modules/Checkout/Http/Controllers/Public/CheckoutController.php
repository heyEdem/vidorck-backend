<?php

namespace App\Modules\Checkout\Http\Controllers\Public;

use App\Modules\Checkout\Actions\StartCheckout;
use App\Modules\Checkout\Http\Requests\CheckoutRequest;
use Illuminate\Http\JsonResponse;

final class CheckoutController
{
    public function store(CheckoutRequest $request, StartCheckout $checkout): JsonResponse
    {
        return response()->json($checkout->execute($request->validated()), 201);
    }
}
