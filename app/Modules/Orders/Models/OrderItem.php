<?php

namespace App\Modules\Orders\Models;

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Orders\Enums\OrderItemLineType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property OrderItemLineType $line_type
 * @property array<string, mixed>|null $bundle_contents_json
 */
class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'line_type', 'parent_item_id', 'product_variant_id', 'bundle_id', 'name_snapshot', 'size_snapshot',
        'product_code_snapshot', 'image_ref_snapshot', 'bundle_contents_json', 'quantity', 'unit_price_minor', 'line_total_minor',
    ];

    protected function casts(): array
    {
        return ['line_type' => OrderItemLineType::class, 'bundle_contents_json' => 'array', 'quantity' => 'integer', 'unit_price_minor' => 'integer', 'line_total_minor' => 'integer'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parentItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_item_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<Bundle, $this> */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }
}
