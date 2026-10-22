<?php

namespace App\Support\Http;

use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ApiException extends HttpException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, string>  $headers
     */
    public function __construct(
        int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $errors = [],
        array $headers = [],
    ) {
        if ($status < 400 || $status >= 500) {
            throw new InvalidArgumentException('Domain API errors must use a 4xx status.');
        }

        parent::__construct($status, $message, headers: $headers);
    }
}
