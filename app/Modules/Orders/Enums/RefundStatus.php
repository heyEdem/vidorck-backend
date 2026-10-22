<?php

namespace App\Modules\Orders\Enums;

enum RefundStatus: string
{
    case REQUESTED = 'REQUESTED';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';

    /** Statuses that block a second request for the same order (matches the partial unique index). */
    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /** @return list<self> */
    public static function open(): array
    {
        return [self::REQUESTED, self::UNDER_REVIEW, self::APPROVED, self::PROCESSING];
    }

    /** Money may already be moving: approved or being processed by the provider. */
    public function isInFlight(): bool
    {
        return $this === self::APPROVED || $this === self::PROCESSING;
    }

    /** @return list<self> */
    public static function inFlight(): array
    {
        return [self::APPROVED, self::PROCESSING];
    }
}
