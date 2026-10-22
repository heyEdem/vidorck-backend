<?php

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Enums\NotificationChannel;

/** Only staging restricts recipients to the allowlist (spec section 9); local, testing and production are unrestricted. */
final class NotificationDeliveryPolicy
{
    public function allows(NotificationChannel $channel, string $recipient): bool
    {
        if (! app()->environment('staging')) {
            return true;
        }
        $config = $channel === NotificationChannel::EMAIL
            ? config('services.notifications.mail_allowed_recipients')
            : config('services.sms.allowed_recipients');
        $allowed = [];
        foreach ((array) $config as $entry) {
            try {
                $allowed[] = RecipientIdentity::normalise($channel, (string) $entry);
            } catch (\InvalidArgumentException) {
                // A malformed allowlist entry never matches.
            }
        }
        try {
            return in_array(RecipientIdentity::normalise($channel, $recipient), $allowed, true);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
