<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Notifications\Enums\NotificationAttemptEvent;
use App\Support\Models\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $notification_log_id
 * @property int $attempt_number
 * @property NotificationAttemptEvent $event_type
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property string|null $error_code
 * @property string|null $error_summary
 * @property CarbonImmutable $created_at
 */
class NotificationAttempt extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $fillable = ['notification_log_id', 'attempt_number', 'event_type', 'provider', 'provider_message_id', 'error_code', 'error_summary', 'created_at'];

    protected function casts(): array
    {
        return ['event_type' => NotificationAttemptEvent::class, 'attempt_number' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
