<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\CatalogNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id */
class ProductVariant extends Model
{
    protected $fillable = [
        'product_id', 'size_label', 'product_code', 'source_price_minor', 'price_minor', 'is_active', 'display_order',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $variant): void {
            $variant->normalized_size = CatalogNormalizer::normalize($variant->size_label);
        });
    }

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'source_price_minor' => 'integer',
            'price_minor' => 'integer',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<BundleItem, $this> */
    public function bundleItems(): HasMany
    {
        return $this->hasMany(BundleItem::class);
    }
}
