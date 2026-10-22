<?php

namespace App\Support;

final readonly class PhoneNumber
{
    private function __construct(public string $e164, public string $display) {}

    public static function fromGhanaInput(string $phone): self
    {
        $display = trim($phone);
        if (! preg_match('/^(?:\\+233|0)[235][0-9]{8}$/', $display)) {
            throw new \InvalidArgumentException('Phone number must be a valid Ghana phone number.');
        }
        $digits = str_starts_with($display, '+233') ? substr($display, 1) : '233'.substr($display, 1);

        return new self('+'.$digits, $display);
    }
}
