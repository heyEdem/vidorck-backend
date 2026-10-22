<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/** @property int $id */
class Bundle extends Model
{
    protected $fillable = ['name', 'slug', 'product_code', 'eyebrow', 'price_minor', 'is_published', 'display_order', 'image_alt'];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'is_published' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /** @return HasMany<BundleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BundleItem::class)->orderBy('display_order')->orderBy('id');
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
            ->whereHas('items')
            ->whereDoesntHave('items', function (Builder $item): void {
                $item->whereHas('variant', fn (Builder $variant): Builder => $variant->where('is_active', false))
                    ->orWhereDoesntHave('variant')
                    ->orWhereHas('variant.product', fn (Builder $product): Builder => $product->where('is_published', false)
                        ->orWhereDoesntHave('category', fn (Builder $category): Builder => $category->where('is_active', true))
                        ->orWhereDoesntHave('variants', fn (Builder $activeVariant): Builder => $activeVariant->where('is_active', true)));
            });
    }
}
