<?php

namespace App\Support\Models;

/** Insert-only Eloquent behaviour; the database triggers are the real guard. */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(static fn (): never => throw new \LogicException(static::class.' is append-only.'));
        static::deleting(static fn (): never => throw new \LogicException(static::class.' is append-only.'));
    }
}
