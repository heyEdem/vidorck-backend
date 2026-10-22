<?php

namespace App\Modules\Checkout\Paystack;

use RuntimeException;

final class PaystackVerificationPending extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Paystack has not completed this transaction.');
    }
}
