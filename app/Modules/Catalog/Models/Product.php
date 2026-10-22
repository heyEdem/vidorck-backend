<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\CatalogNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/** @property int $id */
class Product extends Model
{
    protected $fillable = [
        'name', 'slug', 'category_id', 'display_order', 'is_published', 'is_new_look',
        'image_alt', 'image_position',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            $product->normalized_name = CatalogNormalizer::normalize($product->name);
        });
    }

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'display_order' => 'integer',
            'is_published' => 'boolean',
            'is_new_look' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsToMany<HairType, $this> */
    public function hairTypes(): BelongsToMany
    {
        return $this->belongsToMany(HairType::class, 'product_hair_type')
            ->withPivot('display_order')
            ->orderByPivot('display_order')
            ->orderBy('name');
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('display_order')->orderBy('id');
    }

    /** @return MorphMany<Media, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'owner');
    }

    /** @return MorphOne<Media, $this> */
    public function activeMedia(): MorphOne
    {
        return $this->morphOne(Media::class, 'owner')->whereNull('retired_at')->latestOfMany();
    }

    /** @param Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->whereHas('category', fn (Builder $category): Builder => $category->where('is_active', true))
            ->whereHas('variants', fn (Builder $variant): Builder => $variant->where('is_active', true));
    }
}
