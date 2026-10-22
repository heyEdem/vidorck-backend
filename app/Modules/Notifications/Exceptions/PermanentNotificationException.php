<?php

namespace App\Modules\Notifications\Exceptions;

/** Provider rejected the send in a way retrying cannot fix (invalid recipient, bad credentials). */
class PermanentNotificationException extends \RuntimeException {}
