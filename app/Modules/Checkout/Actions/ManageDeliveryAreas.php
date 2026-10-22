<?php

namespace App\Modules\Checkout\Actions;

use App\Modules\Catalog\Actions\CatalogActorGuard;
use App\Modules\Catalog\Actions\CatalogInvalidation;
use App\Modules\Checkout\Models\DeliveryArea;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class ManageDeliveryAreas
{
    public function __construct(private AuditLogger $audit, private CatalogInvalidation $invalidation) {}

    /** @param array<string, mixed> $input */
    public function create(StaffUser $actor, array $input): DeliveryArea
    {
        $area = DB::transaction(function () use ($actor, $input): DeliveryArea {
            CatalogActorGuard::assertAdmin($actor);

            $name = trim($input['name']);
            $aliases = [];
            if (isset($input['aliases'])) {
                foreach ($input['aliases'] as $alias) {
                    if (is_string($alias)) {
                        $aliases[] = trim($alias);
                    }
                }
            }
            $this->assertNamesAvailable($name, $aliases);

            $area = DeliveryArea::query()->create([
                'name' => $name,
                'aliases_json' => $aliases,
                'is_active' => $input['isActive'] ?? true,
                'display_order' => $input['displayOrder'] ?? 0,
            ]);
            $this->audit->record($actor, 'delivery_area.created', $area, after: [
                'name' => $area->name,
                'aliases' => $area->aliases_json,
                'isActive' => $area->is_active,
                'displayOrder' => $area->display_order,
            ]);

            return $area;
        }, 3);

        $this->invalidation->deliveryAreas();

        return $area;
    }

    /** @param array<string, mixed> $input
     */
    public function update(StaffUser $actor, DeliveryArea $routeArea, array $input): DeliveryArea
    {
        [$area, $changed] = DB::transaction(function () use ($actor, $routeArea, $input): array {
            CatalogActorGuard::assertAdmin($actor);
            $area = DeliveryArea::query()->lockForUpdate()->findOrFail($routeArea->getKey());
            $before = $this->snapshot($area);

            $name = array_key_exists('name', $input) ? trim($input['name']) : $area->name;
            $aliases = $area->aliases_json ?? [];
            if (array_key_exists('aliases', $input)) {
                $aliases = [];
                foreach ($input['aliases'] as $alias) {
                    if (is_string($alias)) {
                        $aliases[] = trim($alias);
                    }
                }
            }
            $this->assertNamesAvailable($name, $aliases, $area->id);

            $area->fill([
                'name' => $name,
                'aliases_json' => $aliases,
                'is_active' => $input['isActive'] ?? $area->is_active,
                'display_order' => $input['displayOrder'] ?? $area->display_order,
            ]);
            $area->save();
            $after = $this->snapshot($area);
            $changed = $before !== $after;
            if ($changed) {
                $this->audit->record($actor, 'delivery_area.updated', $area, $before, $after);
            }

            return [$area, $changed];
        }, 3);

        if ($changed) {
            $this->invalidation->deliveryAreas();
        }

        return $area;
    }

    /** @param list<string> $aliases */
    private function assertNamesAvailable(string $name, array $aliases, ?int $exceptId = null): void
    {
        $nameKey = $this->key($name);
        $incomingKeys = [$nameKey => 'name'];
        foreach ($aliases as $alias) {
            $aliasKey = $this->key($alias);
            if (isset($incomingKeys[$aliasKey])) {
                throw $this->collision('aliases', 'An alias cannot match the delivery area name or another alias.');
            }
            $incomingKeys[$aliasKey] = 'aliases';
        }

        foreach (DeliveryArea::query()->get(['id', 'name', 'aliases_json']) as $existing) {
            if ($existing->id === $exceptId) {
                continue;
            }
            $existingKeys = [$this->key($existing->name)];
            $existingAliases = $existing->aliases_json;
            if ($existingAliases !== null) {
                foreach ($existingAliases as $existingAlias) {
                    $existingKeys[] = $this->key($existingAlias);
                }
            }
            foreach ($incomingKeys as $incomingKey => $field) {
                if (in_array($incomingKey, $existingKeys, true)) {
                    throw $this->collision($field, 'The delivery area name and aliases must be unique across all areas.');
                }
            }
        }
    }

    private function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /** @return array{name: string, aliases: list<string>, isActive: bool, displayOrder: int} */
    private function snapshot(DeliveryArea $area): array
    {
        return [
            'name' => $area->name,
            'aliases' => $area->aliases_json ?? [],
            'isActive' => $area->is_active,
            'displayOrder' => $area->display_order,
        ];
    }

    private function collision(string $field, string $message): ApiException
    {
        return new ApiException(422, 'VALIDATION_ERROR', $message, [$field => [$message]]);
    }
}
