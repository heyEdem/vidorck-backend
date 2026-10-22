<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Actions\ManageBundles;
use App\Modules\Catalog\Http\Requests\AdminBundleIndexRequest;
use App\Modules\Catalog\Http\Requests\AdminBundleStoreRequest;
use App\Modules\Catalog\Http\Requests\AdminBundleUpdateRequest;
use App\Modules\Catalog\Http\Resources\Admin\BundleResource;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

final class BundleController
{
    public function index(AdminBundleIndexRequest $request, ManageBundles $bundles): AnonymousResourceCollection
    {
        $input = $request->validated();
        $all = $bundles->bundles();
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['perPage'] ?? 15);
        $items = new LengthAwarePaginator(array_slice($all, ($page - 1) * $perPage, $perPage), count($all), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]);

        return BundleResource::collection($items);
    }

    public function show(Bundle $bundle): BundleResource
    {
        return new BundleResource($bundle->load(['items.variant.product.category', 'items.variant.product.hairTypes',
            'items.variant.product.variants', 'items.variant.product.activeMedia.owner', 'activeMedia.owner']));
    }

    public function store(AdminBundleStoreRequest $request, ManageBundles $bundles): JsonResponse
    {
        /** @var StaffUser $actor */
        $actor = $request->user();

        return (new BundleResource($bundles->create($actor, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(AdminBundleUpdateRequest $request, Bundle $bundle, ManageBundles $bundles): BundleResource
    {
        /** @var StaffUser $actor */
        $actor = $request->user();

        return new BundleResource($bundles->update($actor, $bundle, $request->validated()));
    }

    public function publish(Request $request, Bundle $bundle, ManageBundles $bundles): BundleResource
    {
        /** @var StaffUser $actor */
        $actor = $request->user();

        return new BundleResource($bundles->setPublished($actor, $bundle, true));
    }

    public function unpublish(Request $request, Bundle $bundle, ManageBundles $bundles): BundleResource
    {
        /** @var StaffUser $actor */
        $actor = $request->user();

        return new BundleResource($bundles->setPublished($actor, $bundle, false));
    }
}
