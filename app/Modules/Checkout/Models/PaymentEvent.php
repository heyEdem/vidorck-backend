<?php

namespace App\Modules\Checkout\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * @property int $id
 * @property string $event_type
 * @property string $reference
 * @property array<string, mixed>|null $payload_redacted_json
 * @property CarbonImmutable|null $processed_at
 */
class PaymentEvent extends Model
{
    protected $fillable = ['provider', 'provider_event_id', 'event_type', 'reference', 'signature_valid', 'payload_redacted_json'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Payment event receipt facts are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Payment event receipts cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return ['signature_valid' => 'boolean', 'payload_redacted_json' => 'array', 'processed_at' => 'immutable_datetime'];
    }

    /** Transition the operational receipt marker from unprocessed once. */
    public function markProcessed(): bool
    {
        $updated = DB::table('payment_events')->where('id', $this->getKey())->whereNull('processed_at')->update([
            'processed_at' => now(), 'updated_at' => now(),
        ]);
        $this->refresh();

        return $updated === 1;
    }
}
