<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** @property int $id */
class HairType extends Model
{
    protected $fillable = ['name', 'slug', 'display_order'];

    protected function casts(): array
    {
        return ['display_order' => 'integer'];
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_hair_type')
            ->withPivot('display_order')
            ->orderByPivot('display_order')
            ->orderBy('name');
    }
}
