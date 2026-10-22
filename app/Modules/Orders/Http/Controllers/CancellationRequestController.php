<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Actions\RequestCancellation;
use App\Modules\Orders\Enums\RefundStatus;
use App\Modules\Orders\Http\Requests\CancellationRequestRequest;
use Illuminate\Http\JsonResponse;

final class CancellationRequestController
{
    public function store(CancellationRequestRequest $request, RequestCancellation $action): JsonResponse
    {
        $refund = $action->execute($request->string('orderNumber')->toString(), $request->string('phone')->toString(), trim($request->string('reason')->toString()));

        return response()->json(['data' => ['status' => RefundStatus::REQUESTED->value, 'requestedAt' => $refund->created_at?->toIso8601String()]], 201);
    }
}
