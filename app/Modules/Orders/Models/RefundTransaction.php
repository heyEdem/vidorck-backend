<?php

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\RefundMethod;
use App\Modules\Orders\Enums\RefundTransactionStatus;
use App\Support\Models\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $refund_request_id
 * @property RefundMethod $method
 * @property string|null $provider_reference
 * @property int $amount_minor
 * @property RefundTransactionStatus $status
 * @property array<string, mixed>|null $response_redacted_json
 * @property CarbonImmutable $created_at
 */
class RefundTransaction extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $fillable = ['refund_request_id', 'method', 'provider_reference', 'amount_minor', 'status', 'response_redacted_json', 'created_at'];

    protected function casts(): array
    {
        return [
            'method' => RefundMethod::class, 'status' => RefundTransactionStatus::class, 'amount_minor' => 'integer',
            'response_redacted_json' => 'array', 'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<RefundRequest, $this> */
    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class);
    }
}
