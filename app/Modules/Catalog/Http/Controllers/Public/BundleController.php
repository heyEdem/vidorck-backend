<?php

namespace App\Modules\Catalog\Http\Controllers\Public;

use App\Modules\Catalog\Http\Resources\BundleResource;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\SlugRedirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class BundleController
{
    public function index(): AnonymousResourceCollection
    {
        return BundleResource::collection(
            Bundle::query()->visible()->with($this->bundleRelations())
                ->orderBy('display_order')->orderBy('name')->orderBy('id')->paginate(15),
        );
    }

    public function show(string $slug): BundleResource|JsonResponse
    {
        $bundle = Bundle::query()->visible()->with($this->bundleRelations())->where('slug', $slug)->first();

        if ($bundle !== null) {
            return new BundleResource($bundle);
        }

        $redirect = $this->visibleRedirect($slug);
        if ($redirect === null) {
            abort(404);
        }

        return response()->json(['redirectTo' => $redirect])->header('Location', '/api/v1/bundles/'.$redirect)->setStatusCode(301);
    }

    /** @return array<array-key, mixed> */
    private function bundleRelations(): array
    {
        return [
            'activeMedia.owner',
            'items.variant',
            'items.variant.product',
            'items.variant.product.category',
            'items.variant.product.hairTypes',
            'items.variant.product.variants' => fn ($variants) => $variants->where('is_active', true),
            'items.variant.product.activeMedia.owner',
        ];
    }

    private function visibleRedirect(string $oldSlug): ?string
    {
        $seen = [$oldSlug => true];
        $current = $oldSlug;

        for ($depth = 0; $depth < 50; $depth++) {
            $next = SlugRedirect::query()->where('entity_type', 'bundle')->where('old_slug', $current)->value('new_slug');
            if (! is_string($next) || isset($seen[$next])) {
                return null;
            }

            $seen[$next] = true;
            $current = $next;

            if (SlugRedirect::query()->where('entity_type', 'bundle')->where('old_slug', $current)->exists()) {
                continue;
            }

            return Bundle::query()->visible()->where('slug', $current)->exists() ? $current : null;
        }

        return null;
    }
}
