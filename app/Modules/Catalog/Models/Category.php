<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id */
class Category extends Model
{
    protected $fillable = ['name', 'slug', 'display_order', 'is_active'];

    protected function casts(): array
    {
        return ['display_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('display_order')->orderBy('name');
    }

    /** @param Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
