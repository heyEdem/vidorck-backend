<?php

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;

class OrderNumberSequence extends Model
{
    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['year', 'last_value'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'last_value' => 'integer'];
    }
}
