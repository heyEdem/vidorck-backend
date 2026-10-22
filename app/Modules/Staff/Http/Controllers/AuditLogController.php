<?php

namespace App\Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Staff\Http\Resources\AuditLogResource;
use App\Modules\Staff\Models\AuditLog;
use App\Support\Http\ApiResourceCollection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AuditLogController extends Controller
{
    public function index(Request $request): ApiResourceCollection
    {
        Gate::authorize('view-audit');
        $input = $request->validate([
            'actorId' => ['sometimes', 'integer', 'min:1'],
            'action' => ['sometimes', 'string', 'max:255'],
            'targetType' => ['sometimes', 'string', 'max:255'],
            'targetId' => ['sometimes', 'string', 'max:255'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $query = AuditLog::query();
        foreach (['actorId' => 'actor_id', 'action' => 'action', 'targetType' => 'target_type', 'targetId' => 'target_id'] as $key => $column) {
            if (isset($input[$key])) {
                $query->where($column, $input[$key]);
            }
        }
        if (isset($input['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($input['from'])->utc());
        }
        if (isset($input['to'])) {
            $to = CarbonImmutable::parse($input['to'])->utc();
            $query->where('created_at', '<=', preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['to']) ? $to->endOfDay() : $to);
        }

        return new ApiResourceCollection($query->orderByDesc('id')->paginate($input['perPage'] ?? 15), AuditLogResource::class);
    }
}
