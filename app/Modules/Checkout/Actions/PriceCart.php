<?php

namespace App\Modules\Checkout\Actions;

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\SlugRedirect;
use App\Support\Http\ApiException;
use Illuminate\Http\Exceptions\HttpResponseException;

final class PriceCart
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{lines: list<array<string, mixed>>, issues: list<array<string, mixed>>}
     */
    public function price(array $items): array
    {
        $lines = [];
        $issues = [];
        foreach ($items as $index => $item) {
            $product = $this->findProduct($item['slug'], $item['productCode']);
            if ($product === null && ($redirectedSlug = $this->redirectedSlug('product', $item['slug'])) !== null) {
                $product = $this->findProduct($redirectedSlug, $item['productCode']);
            }
            if ($product !== null) {
                $variant = $product->variants->firstWhere('product_code', $item['productCode']);
                $line = ['slug' => $product->slug, 'lineType' => 'PRODUCT', 'lineId' => $item['lineId'] ?? null, 'parentLineId' => $item['parentLineId'] ?? null, 'productVariantId' => $variant->id, 'bundleId' => null, 'name' => $product->name, 'size' => $variant->size_label, 'productCode' => $variant->product_code, 'imageRef' => $product->activeMedia?->original_path, 'bundleContents' => null, 'quantity' => (int) $item['quantity'], 'unitPriceMinor' => $variant->price_minor];
            } else {
                $bundle = $this->findBundle($item['slug'], $item['productCode']);
                if ($bundle === null && ($redirectedSlug = $this->redirectedSlug('bundle', $item['slug'])) !== null) {
                    $bundle = $this->findBundle($redirectedSlug, $item['productCode']);
                }
                if ($bundle === null) {
                    $issues[] = ['index' => $index, 'lineId' => $item['lineId'] ?? null, 'reason' => 'ITEM_UNAVAILABLE'];

                    continue;
                }
                $contents = $bundle->items->map(fn ($entry): array => ['productVariantId' => $entry->variant->id, 'name' => $entry->variant->product->name, 'size' => $entry->variant->size_label, 'productCode' => $entry->variant->product_code, 'quantity' => $entry->quantity])->all();
                $line = ['slug' => $bundle->slug, 'lineType' => 'BUNDLE', 'lineId' => $item['lineId'] ?? null, 'parentLineId' => $item['parentLineId'] ?? null, 'productVariantId' => null, 'bundleId' => $bundle->id, 'name' => $bundle->name, 'size' => null, 'productCode' => $bundle->product_code, 'imageRef' => $bundle->activeMedia?->original_path, 'bundleContents' => $contents, 'quantity' => (int) $item['quantity'], 'unitPriceMinor' => $bundle->price_minor];
            }
            if ($line['unitPriceMinor'] > intdiv(PHP_INT_MAX, $line['quantity'])) {
                $issues[] = ['index' => $index, 'lineId' => $item['lineId'] ?? null, 'reason' => 'ITEM_UNAVAILABLE'];

                continue;
            }
            $line['lineTotalMinor'] = $line['unitPriceMinor'] * $line['quantity'];
            if (isset($item['expectedUnitPriceMinor']) && $item['expectedUnitPriceMinor'] !== $line['unitPriceMinor']) {
                $issues[] = ['index' => $index, 'lineId' => $item['lineId'] ?? null, 'reason' => 'PRICE_CHANGED', 'priceMinor' => $line['unitPriceMinor']];

                continue;
            }
            $lines[] = $line;
        }

        if ($issues !== []) {
            $this->throwItemIssues($issues);
        }

        return ['lines' => $lines, 'issues' => []];
    }

    private function findProduct(string $slug, string $productCode): ?Product
    {
        return Product::query()->visible()->with(['activeMedia', 'variants' => fn ($query) => $query->where('is_active', true)])
            ->where('slug', $slug)->whereHas('variants', fn ($query) => $query->where('product_code', $productCode)->where('is_active', true))->first();
    }

    private function findBundle(string $slug, string $productCode): ?Bundle
    {
        return Bundle::query()->visible()->with(['activeMedia', 'items.variant.product.activeMedia'])
            ->where('slug', $slug)->where('product_code', $productCode)->first();
    }

    private function redirectedSlug(string $entityType, string $slug): ?string
    {
        return SlugRedirect::query()->where('entity_type', $entityType)->where('old_slug', $slug)->value('new_slug');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $lines
     */
    public function applyParents(array $items, array &$lines): void
    {
        $byId = [];
        foreach ($lines as $line) {
            if (is_string($line['lineId'])) {
                $byId[$line['lineId']] = $line;
            }
        }
        foreach ($items as $index => $item) {
            $parentId = $item['parentLineId'] ?? null;
            if ($parentId === null) {
                continue;
            }
            if (($byId[$parentId]['lineType'] ?? null) !== 'BUNDLE') {
                throw new ApiException(422, 'VALIDATION_ERROR', 'A bundle extra must reference a bundle in this cart.', ["items.$index.parentLineId" => ['Choose a bundle line in this cart.']]);
            }
            $lineIndex = $index;
            if (($lines[$lineIndex]['lineType'] ?? null) === 'BUNDLE') {
                throw new ApiException(422, 'VALIDATION_ERROR', 'A bundle cannot be a bundle extra.', ["items.$index.parentLineId" => ['Bundles cannot have a parent line.']]);
            }
            $lines[$lineIndex]['lineType'] = 'BUNDLE_EXTRA';
        }
    }

    /** @param list<array<string, mixed>> $issues */
    private function throwItemIssues(array $issues): never
    {
        $errors = [];
        foreach ($issues as $issue) {
            $errors['items.'.$issue['index']][] = $issue['reason'] === 'PRICE_CHANGED' ? 'The price has changed.' : 'This item is no longer available.';
        }
        throw new HttpResponseException(response()->json([
            'message' => 'A product, size, or set in your bag is no longer available or has changed price.',
            'code' => 'CHECKOUT_ITEMS_CHANGED',
            'errors' => (object) $errors,
            'issues' => $issues,
        ], 422));
    }
}
