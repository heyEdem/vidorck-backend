<?php

namespace App\Modules\Complaints\Http\Controllers;

use App\Modules\Complaints\Actions\SubmitComplaint;
use App\Modules\Complaints\Http\Requests\StoreComplaintRequest;
use Illuminate\Http\JsonResponse;

final class PublicComplaintController
{
    public function store(StoreComplaintRequest $request, SubmitComplaint $action): JsonResponse
    {
        if ($request->honeypotFilled()) {
            return response()->json(['data' => ['status' => 'RECEIVED']]);
        }
        $complaint = $action->execute($request->submission());

        return response()->json(['data' => ['reference' => $complaint->reference, 'status' => $complaint->status->value]], 201);
    }
}
