<?php

namespace App\Modules\Checkout\Paystack;

final class PaymentDataAllowlist
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function snapshot(array $data): array
    {
        $safe = [];
        foreach (['id', 'status', 'reference', 'currency', 'amount', 'channel', 'paid_at', 'transaction_reference', 'refund_reference'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) || is_int($value)) {
                $safe[$key] = $value;
            }
        }

        $metadata = self::metadata($data['metadata'] ?? null);
        if (is_string($metadata['attemptId'] ?? null)) {
            $safe['metadata'] = ['attemptId' => $metadata['attemptId']];
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    public static function metadata(mixed $metadata): array
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        return is_array($metadata) ? $metadata : [];
    }
}
