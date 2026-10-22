<?php

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Models\RefundRequest;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CancellationRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly RefundRequest $refundRequest) {}
}
