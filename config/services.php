<?php

return [
    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'callback_url' => env('PAYSTACK_CALLBACK_URL'),
    ],
    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'allowed_recipients' => array_values(array_filter(explode(',', (string) env('SMS_ALLOWED_RECIPIENTS', '')))),
    ],
    'arkesel' => [
        'key' => env('ARKESEL_API_KEY'),
        'base_url' => env('ARKESEL_BASE_URL', 'https://sms.arkesel.com'),
        'sender_id' => env('ARKESEL_SENDER_ID', 'VIDORCK'),
    ],
    'smsonlinegh' => [
        'key' => env('SMSONLINEGH_API_KEY'),
        'host' => env('SMSONLINEGH_HOST', 'api.smsonlinegh.com'),
        'sender_id' => env('SMSONLINEGH_SENDER_ID', 'VIDORCKHAIR'),
    ],
    'storefront' => [
        'url' => env('STOREFRONT_URL', 'http://localhost:3000'),
        'revalidate_url' => env('STOREFRONT_REVALIDATE_URL'),
        'revalidate_secret' => env('REVALIDATE_SECRET') ?: env('STOREFRONT_REVALIDATE_SECRET'),
    ],
    'notifications' => [
        'support_phone' => env('SUPPORT_PHONE'),
        'support_whatsapp' => env('SUPPORT_WHATSAPP'),
        'mail_allowed_recipients' => array_values(array_filter(explode(',', (string) env('MAIL_ALLOWED_RECIPIENTS', '')))),
    ],
];
