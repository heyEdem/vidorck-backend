<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Support\Models\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $idempotency_key
 * @property NotificationChannel $channel
 * @property string $template
 * @property string $recipient_masked
 * @property string $recipient_hash
 * @property string|null $source_type
 * @property string|null $source_id
 * @property CarbonImmutable $created_at
 */
class NotificationLog extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $fillable = ['idempotency_key', 'channel', 'template', 'recipient_masked', 'recipient_hash', 'source_type', 'source_id', 'created_at'];

    protected function casts(): array
    {
        return ['channel' => NotificationChannel::class, 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<NotificationAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(NotificationAttempt::class)->orderBy('id');
    }
}
