<?php

namespace App\Modules\Checkout\Paystack;

use RuntimeException;

/** Definitive provider refusal (4xx): no refund exists. Distinct from PaystackUnavailable, whose outcome is unknown. */
final class PaystackRefundRejected extends RuntimeException {}
