<?php

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Actions\CreateManualOrder;
use App\Modules\Orders\Http\Requests\ManualOrderRequest;
use App\Modules\Orders\Http\Resources\AdminOrderResource;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class ManualOrderController
{
    public function store(ManualOrderRequest $request, CreateManualOrder $action): JsonResponse
    {
        $user = $request->user();
        $order = $action->execute($request->validated(), $user instanceof StaffUser ? $user : throw new AuthenticationException);

        return (new AdminOrderResource($order->load(['customer', 'items', 'statusEvents', 'notes.staff'])))
            ->response()->setStatusCode(201);
    }
}
