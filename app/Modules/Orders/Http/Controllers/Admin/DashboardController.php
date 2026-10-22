<?php

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Actions\BuildDashboard;
use App\Modules\Orders\Http\Requests\DashboardRequest;
use Illuminate\Http\JsonResponse;

final class DashboardController
{
    public function show(DashboardRequest $request, BuildDashboard $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->execute(
            period: $request->validated('period', 'today'),
            from: $request->validated('from'),
            to: $request->validated('to'),
            staff: $request->user(),
        )]);
    }
}
