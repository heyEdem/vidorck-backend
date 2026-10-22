<?php

namespace App\Modules\Catalog\Import;

use App\Modules\Catalog\Actions\CatalogInvalidation;
use App\Modules\Catalog\Actions\StoreImage;
use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\BundleItem;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\HairType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\SlugRedirect;
use App\Modules\Catalog\Support\CatalogNormalizer;
use App\Modules\Staff\Actions\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CatalogImporter
{
    private const MARKUP_MINOR = 200;

    private const MAX_PRICE_MINOR = 1_000_000_000;

    private ImportReport $report;

    private bool $apply = false;

    private ?ImageMatcher $matcher = null;

    /** @var array<string, array{id: ?int}> "productKey|sizeKey" => variant (id null while only planned in a dry run) */
    private array $variants = [];

    /** @var array<string, true> */
    private array $badVariants = [];

    /** @var array<string, string> code => owner key */
    private array $claimedCodes = [];

    /** @var array<string, true> */
    private array $claimedSlugs = [];

    /** @var array<string, list<array<string, mixed>>> product-level values of rows that failed validation */
    private array $partials = [];

    /** @var array<int, true> lookups planned or created during this run, by object id */
    private array $planned = [];

    /** @var array<int, true> */
    private array $seen = [];

    /** @var list<string> */
    private array $tags = [];

    /** @var Collection<int, Category> */
    private Collection $categories;

    /** @var Collection<int, HairType> */
    private Collection $hairTypes;

    public function __construct(private CatalogSnapshot $snapshot, private AuditLogger $audit,
        private CatalogInvalidation $invalidation, private StoreImage $images) {}

    /**
     * @param  array{Products: list<array{row: int, cells: array<string, mixed>}>, Bundles: list<array{row: int, cells: array<string, mixed>}>}  $sheets
     */
    public function run(array $sheets, bool $apply, ?string $imagesDir = null): ImportReport
    {
        $this->report = new ImportReport;
        $this->apply = $apply;
        $this->variants = $this->badVariants = $this->claimedCodes = $this->claimedSlugs = $this->tags = $this->partials = $this->planned = $this->seen = [];
        $this->categories = Category::query()->get();
        $this->hairTypes = HairType::query()->get();
        $this->matcher = $imagesDir !== null ? new ImageMatcher($imagesDir, (array) config('catalog_image_overrides', [])) : null;

        if ($apply) {
            $this->snapshot->create();
        }
        $this->products($sheets['Products']);
        $this->bundles($sheets['Bundles']);
        if ($apply) {
            DB::transaction(function (): void {
                $this->invalidation->import(array_values(array_unique($this->tags)));
                $this->audit->record(null, 'catalog.import.applied', null, [], ['counts' => $this->report->counts(), 'totals' => $this->report->totals()]);
            });
        }

        return $this->report;
    }

    // ---------------------------------------------------------------- products

    /** @param list<array{row: int, cells: array<string, mixed>}> $rows */
    private function products(array $rows): void
    {
        $groups = [];
        foreach ($rows as $row) {
            $parsed = $this->parseProduct($row['row'], $row['cells']);
            if ($parsed !== null) {
                $groups[$parsed['nameKey']][] = $parsed;
            }
        }
        foreach ($groups as $nameKey => $group) {
            $this->group((string) $nameKey, $group);
        }
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>|null
     */
    private function parseProduct(int $row, array $c): ?array
    {
        $errors = [];
        $name = $this->text($c['product_name'], 'product_name', $errors);
        $size = $this->text($c['size'], 'size', $errors);
        $price = $this->money($c['price_ghs'], 'price_ghs', $errors);
        $category = $this->text($c['category'], 'category', $errors);
        $published = $this->flag($c['is_published'], 'is_published', $errors, true);
        $newLook = $this->flag($c['is_new_look'], 'is_new_look', $errors, true);
        $order = $this->uint($c['display_order'], 'display_order', $errors, true);
        $hair = $this->scalar($c['hair_types']) ?? '';
        $imageKey = $this->scalar($c['image_key']);
        $code = $this->code($c['product_code'], 'product_code', $errors);
        $nameKey = $name !== null ? CatalogNormalizer::normalize($name) : '';
        $sizeKey = $size !== null ? CatalogNormalizer::normalize($size) : '';

        $tokens = [];
        foreach (explode(';', $hair) as $token) {
            if (trim($token) !== '') {
                $tokens[CatalogNormalizer::normalize($token)] = trim($token);
            }
        }
        ksort($tokens);

        if ($errors !== []) {
            foreach ($errors as [$field, $reason, $fix]) {
                $this->report->issue('Products', $row, $field, $reason, $fix);
            }
            $this->report->count('variant', 'error');
            if ($nameKey !== '' && $sizeKey !== '') {
                $this->badVariants[self::vkey($nameKey, $sizeKey)] = true;
            }
            if ($nameKey !== '') {
                $this->partials[$nameKey][] = ['row' => $row, 'category' => $category, 'hair' => array_values($tokens), 'published' => $published,
                    'newLook' => $newLook, 'order' => $order, 'imageKey' => $imageKey === '' ? null : $imageKey];
            }

            return null;
        }

        return ['row' => $row, 'name' => $name, 'nameKey' => $nameKey, 'size' => $size, 'sizeKey' => $sizeKey,
            'source' => $price, 'category' => $category, 'hair' => array_values($tokens), 'published' => $published,
            'newLook' => $newLook, 'order' => $order, 'imageKey' => $imageKey === '' ? null : $imageKey, 'code' => $code];
    }

    /** @param list<array<string, mixed>> $rows */
    private function group(string $nameKey, array $rows): void
    {
        $first = $rows[0];
        $fields = ['category' => 'category', 'hair_types' => 'hair', 'is_published' => 'published', 'is_new_look' => 'newLook',
            'display_order' => 'order', 'image_key' => 'imageKey'];
        foreach ($fields as $field => $key) {
            $value = fn (array $r): mixed => is_string($r[$key] ?? null) && $key === 'category' ? CatalogNormalizer::normalize($r[$key])
                : ($key === 'hair' ? implode(';', array_map(CatalogNormalizer::normalize(...), $r['hair'])) : $r[$key]);
            // Rows that failed validation still take part, using only the values that parsed.
            foreach ([...$rows, ...($this->partials[$nameKey] ?? [])] as $row) {
                if ($row[$key] === null && $key !== 'imageKey') {
                    continue;
                }
                if ($value($row) !== $value($first)) {
                    $this->reject($rows, $field, "Rows for '{$first['name']}' disagree on {$field} (first seen in row {$first['row']})", 'Make every row of the product carry the same value');

                    return;
                }
            }
        }

        $sizes = array_count_values(array_column($rows, 'sizeKey'));
        $valid = [];
        foreach ($rows as $index => $row) {
            if ($sizes[$row['sizeKey']] > 1) {
                $this->report->issue('Products', $row['row'], 'size', "Size '{$row['size']}' appears more than once for '{$row['name']}'", 'Keep one row per size');
                $this->report->count('variant', 'error');
                $this->badVariants[self::vkey($nameKey, $row['sizeKey'])] = true;

                continue;
            }
            $row['index'] = $index;
            $valid[] = $row;
        }
        if ($valid === []) {
            $this->report->count('product', 'skipped');

            return;
        }

        $categories = new Collection($this->categories->all());
        $category = $this->lookup($categories, $first['category'], 'category', Category::class);
        if (is_string($category)) {
            $this->reject($valid, 'category', $category, 'Use an existing category name or slug');

            return;
        }
        $hairPool = new Collection($this->hairTypes->all());
        $hairModels = [];
        foreach ($first['hair'] as $token) {
            $hair = $this->lookup($hairPool, $token, 'hair type', HairType::class);
            if (is_string($hair)) {
                $this->reject($valid, 'hair_types', $hair, 'Use hair type names or slugs separated by ;');

                return;
            }
            $hairModels[spl_object_id($hair)] = $hair;
        }
        $hairModels = array_values($hairModels);
        $creates = array_values(array_filter([$category, ...$hairModels], $this->needsCreate(...)));
        $hairNew = array_any($hairModels, fn (Model $m): bool => ! $m->exists);
        $hairIds = $this->ids(array_filter($hairModels, fn (Model $m): bool => $m->exists));

        $existing = Product::query()->where('normalized_name', $nameKey)->get();
        if ($existing->count() > 1) {
            $this->reject($valid, 'product_name', "More than one catalogue product matches '{$first['name']}'", 'Resolve the duplicate in admin');

            return;
        }
        /** @var Product|null $product */
        $product = $existing->first();
        $existingVariants = $product?->variants->keyBy('normalized_size') ?? collect();
        $currentHair = $product === null ? [] : $product->hairTypes()->pluck('hair_types.id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        $plans = [];
        $localCodes = [];
        $rejected = false;
        foreach ($valid as $row) {
            $current = $existingVariants->get($row['sizeKey']);
            $code = $row['code'] ?? $current->product_code ?? 'VHI-'.strtoupper(substr(sha1(self::vkey($nameKey, $row['sizeKey'])), 0, 10));
            $owner = self::vkey($nameKey, $row['sizeKey']);
            if (($this->claimedCodes[$code] ?? $owner) !== $owner || ($localCodes[$code] ?? $owner) !== $owner || $this->codeTaken($code, $current?->id, null)) {
                $this->report->issue('Products', $row['row'], 'product_code', "Product code '{$code}' is already in use", 'Give this row a unique product_code');
                $this->report->count('variant', 'error');
                $this->badVariants[$owner] = true;
                $rejected = true;

                continue;
            }
            $want = ['size_label' => $row['size'], 'product_code' => $code, 'source_price_minor' => $row['source'],
                'price_minor' => $row['source'] + self::MARKUP_MINOR, 'display_order' => $row['index']];
            $same = $current !== null && array_all(array_keys($want), fn (string $k): bool => $current->{$k} === $want[$k]);
            $plans[$row['sizeKey']] = ['want' => $want, 'current' => $current, 'status' => $current === null ? 'created' : ($same ? 'unchanged' : 'updated')];
            $localCodes[$code] = $owner;
        }
        if ($plans === []) {
            $this->report->count('product', 'skipped');

            return;
        }

        $attrs = ['name' => $first['name'], 'category_id' => $category->exists ? (int) $category->getKey() : null, 'display_order' => $first['order'],
            'is_published' => $first['published'], 'is_new_look' => $first['newLook']];
        $productChanged = $product === null || array_any(array_keys($attrs), fn (string $k): bool => $product->{$k} !== $attrs[$k]);
        $hairChanged = $hairNew || ($product === null ? $hairIds !== [] : $currentHair !== $hairIds);
        if ($product === null) {
            $slug = Str::slug($first['name']);
            if ($slug === '' || mb_strlen($slug) > 255 || $this->slugTaken($slug)) {
                $this->reject($valid, 'product_name', "Cannot derive a free slug ('{$slug}') for '{$first['name']}'", 'Rename the product or create it in admin with an explicit slug');

                return;
            }
            $attrs['slug'] = $slug;
        }
        // Reserve codes only now that the product is accepted.
        $this->claimedCodes = array_replace($this->claimedCodes, $localCodes);
        $status = $product === null ? 'created' : ($productChanged || $hairChanged ? 'updated' : 'unchanged');
        $dirty = $status !== 'unchanged' || array_any($plans, fn (array $p): bool => $p['status'] !== 'unchanged');

        $ids = [];
        if ($this->apply && $dirty) {
            try {
                [$product, $ids] = DB::transaction(function () use ($product, $attrs, $productChanged, $hairChanged, $hairModels, $category, $creates, $plans): array {
                    foreach ($creates as $lookup) {
                        $this->createLookup($lookup);
                    }
                    $attrs['category_id'] = (int) $category->getKey();
                    if ($product === null) {
                        $product = Product::create($attrs);
                    } elseif ($productChanged) {
                        $product->fill($attrs)->save();
                    }
                    if ($hairChanged) {
                        $product->hairTypes()->sync($this->ids($hairModels));
                    }
                    $ids = [];
                    foreach ($plans as $key => $plan) {
                        $ids[$key] = match ($plan['status']) {
                            'unchanged' => $plan['current']->id,
                            'updated' => $this->saveVariant($plan['current'], $plan['want']),
                            default => ProductVariant::create($plan['want'] + ['product_id' => $product->id])->id,
                        };
                    }

                    return [$product, $ids];
                });
            } catch (Throwable $exception) {
                foreach ($creates as $lookup) {
                    $lookup->exists = false;
                    $lookup->setAttribute('id', null);
                }
                $this->claimedCodes = array_diff_key($this->claimedCodes, $localCodes);
                foreach ($plans as $key => $plan) {
                    $this->badVariants[self::vkey($nameKey, (string) $key)] = true;
                }
                $this->reject($valid, 'product_name', 'Database write failed: '.Str::limit($exception->getMessage(), 200), 'Fix the data and re-run');

                return;
            }
        }

        $this->lookups($category, $hairModels, $creates, $first);
        $this->report->count('product', $status);
        foreach ($plans as $key => $plan) {
            $this->report->count('variant', $plan['status']);
            $this->variants[self::vkey($nameKey, (string) $key)] = ['id' => $ids[$key] ?? $plan['current']?->id];
        }
        $slug = (string) ($attrs['slug'] ?? $product->slug);
        $this->claimedSlugs[$slug] = true;
        if ($dirty) {
            $this->tags[] = 'product:'.$slug;
        }
        $this->image($product, $first, $slug);
    }

    /**
     * @param  list<Model>  $hairModels
     * @param  list<Model>  $creates
     * @param  array<string, mixed>  $first
     */
    private function lookups(Model $category, array $hairModels, array $creates, array $first): void
    {
        foreach ([[$category, 'category', 'category'], ...array_map(fn (Model $m): array => [$m, 'hair_type', 'hair_types'], $hairModels)] as [$model, $entity, $field]) {
            $id = spl_object_id($model);
            if (in_array($model, $creates, true)) {
                $this->report->count($entity, 'created');
                $this->report->note('Products', $first['row'], $field, ($this->apply ? 'Created ' : 'Would create ').str_replace('_', ' ', $entity)." '{$model->getAttribute('name')}'", 'Check the spelling; rename in admin if it is a typo');
                $this->planned[$id] = true;
                if ($model instanceof Category) {
                    $this->categories->push($model);
                } elseif ($model instanceof HairType) {
                    $this->hairTypes->push($model);
                }
                if ($model instanceof Category) {
                    $this->tags[] = 'categories';
                }
            } elseif (! isset($this->seen[$id])) {
                $this->report->count($entity, 'unchanged');
            }
            $this->seen[$id] = true;
        }
    }

    private function needsCreate(Model $model): bool
    {
        return ! $model->exists && ! isset($this->planned[spl_object_id($model)]);
    }

    private function createLookup(Model $model): void
    {
        $max = $model::query()->max('display_order');
        $model->setAttribute('display_order', $max === null ? 0 : ((int) $max) + 1);
        $model->save();
    }

    /**
     * @param  iterable<Model>  $models
     * @return list<int>
     */
    private function ids(iterable $models): array
    {
        $ids = [];
        foreach ($models as $model) {
            $ids[] = (int) $model->getKey();
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $items  working copy; a newly planned lookup is appended
     * @param  class-string<TModel>  $class
     * @return TModel|string
     */
    private function lookup(Collection $items, string $input, string $label, string $class): Model|string
    {
        $found = $this->resolve($items, $input, $label);
        if ($found !== null) {
            return $found;
        }
        $name = trim($input);
        $slug = Str::slug($name);
        if ($slug === '' || mb_strlen($name) > 255) {
            return "Cannot create {$label} '{$input}': it has no usable slug";
        }
        $model = new $class(['name' => $name, 'slug' => $slug] + ($class === Category::class ? ['is_active' => true] : []));
        $items->push($model);

        return $model;
    }

    /** @param array<string, mixed> $want */
    private function saveVariant(ProductVariant $variant, array $want): int
    {
        $variant->fill($want)->save();

        return $variant->id;
    }

    /** @param array<string, mixed> $first */
    private function image(?Product $product, array $first, string $slug): void
    {
        if ($this->matcher === null) {
            return;
        }
        if ($product !== null && $product->media()->whereNull('retired_at')->exists()) {
            $this->report->count('image', 'unchanged');

            return;
        }
        $match = $this->matcher->match($first['name'], $first['imageKey']);
        if ($match['path'] === null) {
            $this->report->issue('Products', $first['row'], 'image_key', (string) $match['reason'], (string) $match['fix']);
            $this->report->count('image', 'error');

            return;
        }
        if (! $this->apply || $product === null) {
            $this->report->count('image', 'created');

            return;
        }
        try {
            $file = new UploadedFile($match['path'], basename($match['path']), null, null, true);
            $this->images->storeAsSystem($product, $file, $product->image_alt === null ? $product->name : null);
            $this->report->count('image', 'created');
            $this->tags[] = 'product:'.$slug;
        } catch (Throwable $exception) {
            $reason = $exception instanceof ValidationException ? (string) collect($exception->errors())->flatten()->first() : $exception->getMessage();
            $this->report->issue('Products', $first['row'], 'image_key', 'Image rejected: '.Str::limit($reason, 200), 'Use a JPEG, PNG or WebP under 8 MB');
            $this->report->count('image', 'error');
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function reject(array $rows, string $field, string $reason, string $fix): void
    {
        foreach ($rows as $row) {
            $this->report->issue('Products', $row['row'], $field, $reason, $fix);
            $this->badVariants[self::vkey($row['nameKey'], $row['sizeKey'])] = true;
        }
        $this->report->count('product', 'error');
        $this->report->count('variant', 'skipped', count($rows));
        if ($this->matcher !== null) {
            $this->report->count('image', 'skipped');
        }
    }

    // ----------------------------------------------------------------- bundles

    /** @param list<array{row: int, cells: array<string, mixed>}> $rows */
    private function bundles(array $rows): void
    {
        $groups = [];
        $broken = [];
        foreach ($rows as $row) {
            $c = $row['cells'];
            $errors = [];
            $name = $this->text($c['bundle_name'], 'bundle_name', $errors);
            $price = $this->money($c['fixed_price_ghs'], 'fixed_price_ghs', $errors, false);
            $product = $this->text($c['product_name'], 'product_name', $errors);
            $size = $this->text($c['size'], 'size', $errors);
            $quantity = $this->uint($c['quantity'], 'quantity', $errors, true, 1);
            $order = $this->uint($c['display_order'], 'display_order', $errors, true);
            $code = $this->code($c['bundle_code'], 'bundle_code', $errors);
            $published = $this->flag($c['is_published'], 'is_published', $errors, false);
            $key = $name !== null ? CatalogNormalizer::normalize($name) : '';
            foreach ($errors as [$field, $reason, $fix]) {
                $this->report->issue('Bundles', $row['row'], $field, $reason, $fix);
            }
            if ($errors !== []) {
                if ($key !== '') {
                    $broken[$key] = true;
                } else {
                    $this->report->count('bundle', 'error');
                }

                continue;
            }
            $groups[$key][] = ['row' => $row['row'], 'name' => $name, 'price' => $price, 'product' => $product, 'size' => $size,
                'quantity' => $quantity, 'order' => $order, 'code' => $code, 'published' => $published];
        }
        $existing = [];
        foreach (Bundle::query()->get() as $bundle) {
            $existing[CatalogNormalizer::normalize($bundle->name)][] = $bundle;
        }
        $this->report->count('bundle', 'error', count($broken));
        foreach ($groups as $key => $group) {
            if (isset($broken[(string) $key])) {
                continue;
            }
            $this->bundle((string) $key, $group, $existing[(string) $key] ?? []);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<Bundle>  $matches
     */
    private function bundle(string $key, array $rows, array $matches): void
    {
        $first = $rows[0];
        $fail = function (array $items, string $field, string $reason, string $fix): void {
            foreach ($items as $item) {
                $this->report->issue('Bundles', $item['row'], $field, $reason, $fix);
            }
            $this->report->count('bundle', 'error');
        };
        foreach (['fixed_price_ghs' => 'price', 'bundle_code' => 'code', 'is_published' => 'published'] as $field => $attribute) {
            foreach ($rows as $row) {
                if ($row[$attribute] !== $first[$attribute]) {
                    $fail($rows, $field, "Rows for bundle '{$first['name']}' disagree on {$field} (first seen in row {$first['row']})", 'Use one value for the whole bundle');

                    return;
                }
            }
        }
        if (count($matches) > 1) {
            $fail($rows, 'bundle_name', "More than one bundle matches '{$first['name']}'", 'Resolve the duplicate in admin');

            return;
        }

        $items = [];
        $seen = [];
        $problems = 0;
        foreach ($rows as $row) {
            $variantKey = self::vkey(CatalogNormalizer::normalize($row['product']), CatalogNormalizer::normalize($row['size']));
            [$id, $problem] = $this->component($row['product'], $row['size'], $variantKey);
            if ($problem === null && isset($seen[$variantKey])) {
                $problem = "Component '{$row['product']}' / '{$row['size']}' is listed more than once";
            }
            if ($problem !== null) {
                $this->report->issue('Bundles', $row['row'], 'product_name/size', $problem, 'Fix the component or add it to the Products sheet');
                $problems++;

                continue;
            }
            $seen[$variantKey] = true;
            $items[] = ['variant_id' => $id, 'quantity' => $row['quantity'], 'display_order' => $row['order']];
        }
        if ($problems > 0) {
            $this->report->count('bundle', 'error');

            return;
        }

        /** @var Bundle|null $bundle */
        $bundle = $matches[0] ?? null;
        $code = $first['code'] ?? $bundle->product_code ?? 'BDL-'.strtoupper(substr(sha1($key), 0, 10));
        $owner = 'bundle:'.$key;
        if (($this->claimedCodes[$code] ?? $owner) !== $owner || $this->codeTaken($code, null, $bundle?->id)) {
            $fail($rows, 'bundle_code', "Product code '{$code}' is already in use", 'Give this bundle a unique bundle_code');

            return;
        }
        $attrs = ['name' => $first['name'], 'price_minor' => $first['price'], 'product_code' => $code,
            'is_published' => $first['published'] ?? $bundle->is_published ?? false];
        if ($bundle === null) {
            $slug = Str::slug($first['name']);
            if ($slug === '' || mb_strlen($slug) > 255 || $this->slugTaken($slug)) {
                $fail($rows, 'bundle_name', "Cannot derive a free slug ('{$slug}') for '{$first['name']}'", 'Rename the bundle or create it in admin');

                return;
            }
            $attrs['slug'] = $slug;
        }
        // Reserve the code only now that the bundle is accepted.
        $this->claimedCodes[$code] = $owner;
        usort($items, fn (array $a, array $b): int => [$a['display_order'], $a['variant_id'] ?? 0] <=> [$b['display_order'], $b['variant_id'] ?? 0]);
        $current = $bundle?->items->map(fn (BundleItem $i): array => ['variant_id' => $i->product_variant_id, 'quantity' => $i->quantity,
            'display_order' => $i->display_order])->sort(fn (array $a, array $b): int => [$a['display_order'], $a['variant_id']] <=> [$b['display_order'], $b['variant_id']])->values()->all();
        $itemsChanged = $bundle === null || $current !== $items;
        $fieldsChanged = $bundle === null || array_any(array_keys($attrs), fn (string $k): bool => $bundle->{$k} !== $attrs[$k]);
        $status = $bundle === null ? 'created' : ($itemsChanged || $fieldsChanged ? 'updated' : 'unchanged');

        if ($this->apply && $status !== 'unchanged') {
            try {
                $bundle = DB::transaction(function () use ($bundle, $attrs, $fieldsChanged, $itemsChanged, $items): Bundle {
                    if ($bundle === null) {
                        $bundle = Bundle::create($attrs);
                    } elseif ($fieldsChanged) {
                        $bundle->fill($attrs)->save();
                    }
                    if ($itemsChanged) {
                        BundleItem::query()->where('bundle_id', $bundle->id)->delete();
                        foreach ($items as $item) {
                            BundleItem::create(['bundle_id' => $bundle->id, 'product_variant_id' => $item['variant_id'],
                                'quantity' => $item['quantity'], 'display_order' => $item['display_order']]);
                        }
                    }

                    return $bundle;
                });
            } catch (Throwable $exception) {
                unset($this->claimedCodes[$code]);
                $fail($rows, 'bundle_name', 'Database write failed: '.Str::limit($exception->getMessage(), 200), 'Fix the data and re-run');

                return;
            }
        }
        $this->report->count('bundle', $status);
        $slug = $attrs['slug'] ?? $bundle->slug ?? '';
        $this->claimedSlugs[$slug] = true;
        if ($status !== 'unchanged') {
            $this->tags[] = 'bundle:'.$slug;
        }
    }

    /** @return array{?int, ?string} variant id and a problem description */
    private function component(string $product, string $size, string $variantKey): array
    {
        if (isset($this->badVariants[$variantKey])) {
            return [null, "'{$product}' / '{$size}' has an error on the Products sheet"];
        }
        if (isset($this->variants[$variantKey])) {
            return [$this->variants[$variantKey]['id'], null];
        }
        $products = Product::query()->where('normalized_name', CatalogNormalizer::normalize($product))->get();
        if ($products->count() !== 1) {
            return [null, $products->isEmpty() ? "Unknown product '{$product}'" : "More than one product matches '{$product}'"];
        }
        $variant = ProductVariant::query()->where('product_id', $products->first()?->id)
            ->where('normalized_size', CatalogNormalizer::normalize($size))->first();

        return $variant === null ? [null, "Unknown size '{$size}' for product '{$product}'"] : [$variant->id, null];
    }

    // ----------------------------------------------------------------- helpers

    private static function vkey(string $name, string $size): string
    {
        return json_encode([$name, $size], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  Collection<int, Category>|Collection<int, HairType>  $items
     */
    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $items
     * @return TModel|string|null
     */
    private function resolve(Collection $items, string $input, string $label): Model|string|null
    {
        $normal = CatalogNormalizer::normalize($input);
        $slug = Str::slug($input);
        $hits = $items->filter(fn (Model $m): bool => CatalogNormalizer::normalize((string) $m->getAttribute('name')) === $normal
            || ($slug !== '' && $m->getAttribute('slug') === $slug))->values();

        return match ($hits->count()) {
            1 => $hits->first(),
            0 => null,
            default => "More than one {$label} matches '{$input}'",
        };
    }

    private function slugTaken(string $slug): bool
    {
        return isset($this->claimedSlugs[$slug]) || DB::table('products')->where('slug', $slug)->exists()
            || DB::table('bundles')->where('slug', $slug)->exists() || SlugRedirect::query()->where('old_slug', $slug)->exists();
    }

    private function codeTaken(string $code, ?int $exceptVariantId, ?int $exceptBundleId): bool
    {
        return ProductVariant::query()->where('product_code', $code)->when($exceptVariantId, fn ($q) => $q->where('id', '<>', $exceptVariantId))->exists()
            || Bundle::query()->where('product_code', $code)->when($exceptBundleId, fn ($q) => $q->where('id', '<>', $exceptBundleId))->exists();
    }

    private function scalar(mixed $value): ?string
    {
        if ($value === null || is_bool($value)) {
            return null;
        }
        if (is_float($value)) {
            $value = floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        return is_scalar($value) ? trim((string) $value) : null;
    }

    /** @param list<array{string, string, string}> $errors */
    private function text(mixed $value, string $field, array &$errors): ?string
    {
        $text = $this->scalar($value);
        if ($text === null || $text === '') {
            $errors[] = [$field, 'Required value is blank', "Fill in {$field}"];

            return null;
        }
        if (mb_strlen($text) > 255) {
            $errors[] = [$field, 'Value is longer than 255 characters', 'Shorten it'];

            return null;
        }

        return $text;
    }

    /** @param list<array{string, string, string}> $errors */
    private function money(mixed $value, string $field, array &$errors, bool $markup = true): ?int
    {
        $text = $this->scalar($value);
        if ($text === null || $text === '') {
            $errors[] = [$field, 'Price is missing', 'Enter the price in GHS, e.g. 45.50'];

            return null;
        }
        if (preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/', $text, $m) !== 1) {
            $errors[] = [$field, "'{$text}' is not a GHS amount with at most 2 decimals", 'Use digits only, e.g. 45.50'];

            return null;
        }
        $minor = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($minor <= 0 || $minor + ($markup ? self::MARKUP_MINOR : 0) > self::MAX_PRICE_MINOR) {
            $errors[] = [$field, 'Price must be greater than zero and within the catalogue limit', 'Correct the price'];

            return null;
        }

        return $minor;
    }

    /** @param list<array{string, string, string}> $errors */
    private function flag(mixed $value, string $field, array &$errors, bool $required): ?bool
    {
        $text = is_bool($value) ? ($value ? '1' : '0') : $this->scalar($value);
        if ($text === null || $text === '') {
            if ($required) {
                $errors[] = [$field, 'Required value is blank', 'Use 0 or 1'];
            }

            return null;
        }
        if ($text !== '0' && $text !== '1') {
            $errors[] = [$field, "'{$text}' is not 0 or 1", 'Use 0 or 1'];

            return null;
        }

        return $text === '1';
    }

    /** @param list<array{string, string, string}> $errors */
    private function uint(mixed $value, string $field, array &$errors, bool $required, int $min = 0): ?int
    {
        $text = $this->scalar($value);
        if ($text === null || $text === '') {
            if ($required) {
                $errors[] = [$field, 'Required value is blank', 'Enter a whole number'];
            }

            return null;
        }
        if (preg_match('/^\d{1,9}$/', $text) !== 1 || (int) $text < $min) {
            $errors[] = [$field, "'{$text}' is not a whole number >= {$min}", 'Enter a whole number'];

            return null;
        }

        return (int) $text;
    }

    /** @param list<array{string, string, string}> $errors */
    private function code(mixed $value, string $field, array &$errors): ?string
    {
        $text = $this->scalar($value);
        if ($text === null || $text === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9._-]{1,100}$/', $text) !== 1) {
            $errors[] = [$field, "'{$text}' is not a valid code", 'Use letters, digits, . _ - (max 100)'];

            return null;
        }

        return $text;
    }
}
