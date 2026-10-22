<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id */
class BundleItem extends Model
{
    protected $fillable = ['bundle_id', 'product_variant_id', 'quantity', 'display_order'];

    protected function casts(): array
    {
        return [
            'bundle_id' => 'integer',
            'product_variant_id' => 'integer',
            'quantity' => 'integer',
            'display_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Bundle, $this> */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
