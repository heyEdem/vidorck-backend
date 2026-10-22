<?php

declare(strict_types=1);

namespace App\Support;

use JsonSerializable;
use TypeError;

final readonly class Money implements JsonSerializable
{
    public int $amountMinor;

    public string $currency;

    public function __construct(mixed $amountMinor)
    {
        if (! is_int($amountMinor)) {
            throw new TypeError('Money requires an integer amount in pesewas.');
        }

        $this->amountMinor = $amountMinor;
        $this->currency = 'GHS';
    }

    /** @return array{amountMinor: int, currency: string} */
    public function jsonSerialize(): array
    {
        return ['amountMinor' => $this->amountMinor, 'currency' => $this->currency];
    }
}
