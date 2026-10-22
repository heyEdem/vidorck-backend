<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** @property int $id */
class SlugRedirect extends Model
{
    protected $fillable = ['entity_type', 'old_slug', 'new_slug'];
}
