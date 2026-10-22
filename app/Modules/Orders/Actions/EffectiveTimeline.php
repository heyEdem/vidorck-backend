<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Models\OrderStatusEvent;
use Illuminate\Support\Collection;

/**
 * Customer-facing path: replays status events in order; a step back (Admin correction) drops the statuses it
 * reverses, and each status appears once with its latest timestamp. Reasons and actors are never exposed.
 */
final class EffectiveTimeline
{
    /**
     * @param  Collection<int, OrderStatusEvent>  $events
     * @return list<array{status: string, at: string|null}>
     */
    public function build(Collection $events): array
    {
        $path = [];
        foreach ($events->sortBy('id') as $event) {
            $status = $event->to_status->value;
            $at = $event->created_at?->toIso8601String();
            $position = array_search($status, array_column($path, 'status'), true);
            if ($position !== false) {
                $path = array_slice($path, 0, $position);
            }
            $path[] = ['status' => $status, 'at' => $at];
        }

        return $path;
    }
}
