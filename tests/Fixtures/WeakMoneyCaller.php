<?php

namespace Tests\Fixtures;

use App\Support\Money;

// Deliberately omit strict_types to exercise callers using PHP's default mode.
final class WeakMoneyCaller
{
    public static function create(mixed $amount): Money
    {
        return new Money($amount);
    }
}
