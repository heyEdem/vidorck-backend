<?php

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;

final class UpsertCustomer
{
    /** @param array<string, mixed> $snapshot */
    public function execute(array $snapshot, int $amountMinor, \DateTimeInterface $paidAt): Customer
    {
        $phone = $snapshot['phoneE164'] ?? null;
        if (! is_string($phone) || $phone === '' || ! is_string($snapshot['fullName'] ?? null) || $snapshot['fullName'] === '') {
            throw new \InvalidArgumentException('Checkout customer snapshot is invalid.');
        }

        $customer = Customer::query()->where('phone_e164', $phone)->lockForUpdate()->first();
        if ($customer === null) {
            return Customer::query()->create([
                'full_name' => $snapshot['fullName'], 'phone_e164' => $phone,
                'phone_display' => (string) ($snapshot['phoneDisplay'] ?? $phone), 'email' => $snapshot['email'] ?? null,
                'first_order_at' => $paidAt, 'last_order_at' => $paidAt,
                'orders_count' => 1, 'lifetime_paid_minor' => $amountMinor,
            ]);
        }

        $firstOrderAt = $customer->first_order_at;
        if ($firstOrderAt === null || $paidAt < $firstOrderAt) {
            $firstOrderAt = $paidAt;
        }
        $lastOrderAt = $customer->last_order_at;
        $isLatestOrder = $lastOrderAt === null || $paidAt >= $lastOrderAt;
        if ($lastOrderAt === null || $paidAt > $lastOrderAt) {
            $lastOrderAt = $paidAt;
        }

        $customer->forceFill([
            'full_name' => $isLatestOrder ? $snapshot['fullName'] : $customer->full_name,
            'phone_display' => $isLatestOrder ? (string) ($snapshot['phoneDisplay'] ?? $phone) : $customer->phone_display,
            'email' => $isLatestOrder ? ($snapshot['email'] ?? $customer->email) : $customer->email,
            'first_order_at' => $firstOrderAt,
            'last_order_at' => $lastOrderAt,
            'orders_count' => $customer->orders_count + 1,
            'lifetime_paid_minor' => $customer->lifetime_paid_minor + $amountMinor,
        ])->save();

        return $customer;
    }
}
