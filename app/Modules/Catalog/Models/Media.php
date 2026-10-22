<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Staff\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** @property int $id */
class Media extends Model
{
    protected $fillable = [
        'public_id', 'original_path', 'variants_json', 'width', 'height', 'mime', 'bytes', 'uploaded_by', 'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'variants_json' => 'array',
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'uploaded_by' => 'integer',
            'retired_at' => 'immutable_datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<StaffUser, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'uploaded_by');
    }
}
