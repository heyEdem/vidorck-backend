<?php

namespace App\Modules\Notifications\Support;

/** Bounded, PII-free error text for notification_attempts. */
final class ErrorSanitizer
{
    public const MAX = 200;

    public static function clean(string $message): string
    {
        $message = preg_replace('/[^\S ]+/u', ' ', $message) ?? '';
        $message = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+/', '[email]', $message) ?? '';
        $message = preg_replace('/\+?\d[\d\s\-()]{6,}\d/', '[number]', $message) ?? '';
        $message = preg_replace('/\b[A-Za-z0-9_\-]{24,}\b/', '[redacted]', $message) ?? '';

        return mb_substr(trim($message), 0, self::MAX);
    }
}
