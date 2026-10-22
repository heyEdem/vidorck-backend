<?php

namespace App\Modules\Checkout\Http\Controllers\Admin;

use App\Modules\Checkout\Actions\ManageDeliveryAreas;
use App\Modules\Checkout\Http\Requests\AdminDeliveryAreaIndexRequest;
use App\Modules\Checkout\Http\Requests\AdminDeliveryAreaStoreRequest;
use App\Modules\Checkout\Http\Requests\AdminDeliveryAreaUpdateRequest;
use App\Modules\Checkout\Http\Resources\Admin\DeliveryAreaResource;
use App\Modules\Checkout\Models\DeliveryArea;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DeliveryAreaController
{
    public function index(AdminDeliveryAreaIndexRequest $request): AnonymousResourceCollection
    {
        return DeliveryAreaResource::collection(DeliveryArea::query()->orderBy('display_order')->orderBy('id')->orderBy('name')->get());
    }

    public function store(AdminDeliveryAreaStoreRequest $request, ManageDeliveryAreas $deliveryAreas): JsonResponse
    {
        /** @var StaffUser $actor */
        $actor = $request->user();

        return (new DeliveryAreaResource($deliveryAreas->create($actor, $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(AdminDeliveryAreaUpdateRequest $request, DeliveryArea $deliveryArea, ManageDeliveryAreas $deliveryAreas): DeliveryAreaResource
    {
        /** @var StaffUser $actor */
        $actor = $request->user();

        return new DeliveryAreaResource($deliveryAreas->update($actor, $deliveryArea, $request->validated()));
    }
}
