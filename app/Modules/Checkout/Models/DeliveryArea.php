<?php

namespace App\Modules\Checkout\Models;

use Illuminate\Database\Eloquent\Model;

/** @property list<string>|null $aliases_json */
class DeliveryArea extends Model
{
    protected $fillable = ['name', 'aliases_json', 'is_active', 'display_order'];

    protected function casts(): array
    {
        return ['aliases_json' => 'array', 'is_active' => 'boolean', 'display_order' => 'integer'];
    }
}
