<?php

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Support\PhoneNumber;

/** Normalised recipient with a masked display form and a non-reversible hash. */
final readonly class RecipientIdentity
{
    private function __construct(public string $normalised, public string $masked, public string $hash) {}

    public static function for(NotificationChannel $channel, string $recipient): self
    {
        $normalised = self::normalise($channel, $recipient);

        return new self($normalised, self::mask($channel, $normalised), hash('sha256', $normalised));
    }

    public static function normalise(NotificationChannel $channel, string $recipient): string
    {
        $value = trim($recipient);
        if ($channel === NotificationChannel::SMS) {
            return PhoneNumber::fromGhanaInput($value)->e164;
        }
        if ($value === '' || ! str_contains($value, '@')) {
            throw new \InvalidArgumentException('Email recipient is invalid.');
        }

        return strtolower($value);
    }

    private static function mask(NotificationChannel $channel, string $normalised): string
    {
        if ($channel === NotificationChannel::SMS) {
            return substr($normalised, 0, 4).str_repeat('*', max(0, strlen($normalised) - 7)).substr($normalised, -3);
        }
        [$local, $domain] = explode('@', $normalised, 2);

        return substr($local, 0, 1).'***@'.$domain;
    }
}
