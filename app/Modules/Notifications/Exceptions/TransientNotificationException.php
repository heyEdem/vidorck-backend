<?php

namespace App\Modules\Notifications\Exceptions;

/** Timeout, 5xx or rate limit; the job retries with backoff. Any other Throwable is treated the same way. */
class TransientNotificationException extends \RuntimeException {}
