<?php

namespace App\Modules\Notifications\Support;

/** Frozen, serialisable snapshot of what to send. Never carries live models. */
final readonly class NotificationPayload
{
    /** @param array<string, mixed> $data scalar/array snapshot values only */
    public function __construct(
        public string $template,
        public array $data = [],
        public ?string $sourceType = null,
        public string|int|null $sourceId = null,
    ) {}
}
