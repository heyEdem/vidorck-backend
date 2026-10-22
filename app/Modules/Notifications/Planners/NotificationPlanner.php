<?php

namespace App\Modules\Notifications\Planners;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\NotificationDispatcher;
use App\Modules\Notifications\Support\NotificationPayload;
use App\Modules\Notifications\Support\RecipientIdentity;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Shared, failure-proof recipient handling for planners. Planners run inside business transactions, so a bad
 * recipient or any planning error is logged (without the raw value) and skipped; it must never fail the caller.
 */
abstract class NotificationPlanner
{
    public function __construct(protected readonly NotificationDispatcher $dispatcher) {}

    /** Runs one planning step; any error is logged by class only and swallowed. */
    protected function guard(string $what, callable $plan): void
    {
        try {
            $plan();
        } catch (\Throwable $e) {
            Log::warning('Notification planning failed and was skipped.', ['what' => $what, 'error' => class_basename($e)]);
        }
    }

    /** @param array<string, mixed> $data */
    protected function email(string $occurrence, ?string $recipient, string $template, array $data, string $sourceType, int $sourceId): void
    {
        $this->guard($occurrence, function () use ($occurrence, $recipient, $template, $data, $sourceType, $sourceId): void {
            $email = $this->validEmail($recipient);
            if ($email === null) {
                if ($recipient !== null && trim($recipient) !== '') {
                    Log::warning('Notification skipped: invalid email recipient.', ['occurrence' => $occurrence]);
                }

                return;
            }
            $this->dispatcher->once(NotificationDispatcher::key($occurrence, NotificationChannel::EMAIL, $email), NotificationChannel::EMAIL, $email,
                new NotificationPayload($template, $data, $sourceType, $sourceId));
        });
    }

    /** @param array<string, mixed> $data */
    protected function sms(string $occurrence, string $phone, string $template, array $data, string $sourceType, int $sourceId): void
    {
        $this->guard($occurrence, function () use ($occurrence, $phone, $template, $data, $sourceType, $sourceId): void {
            $this->dispatcher->once(NotificationDispatcher::key($occurrence, NotificationChannel::SMS, $phone), NotificationChannel::SMS, $phone,
                new NotificationPayload($template, $data, $sourceType, $sourceId));
        });
    }

    protected function validEmail(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    /** @return array<string, mixed> frozen customer/delivery/item snapshot for templates */
    protected function orderData(Order $order): array
    {
        $customer = (array) ($order->customer_snapshot_json ?? []);
        $delivery = (array) ($order->delivery_snapshot_json ?? []);
        $items = [];
        // Top-level lines always; child lines (bundle extras) only when they carry a price. Free composition lines stay hidden.
        foreach ($order->items()->orderBy('id')->get() as $item) {
            $child = $item->parent_item_id !== null;
            if ($child && (int) $item->line_total_minor <= 0) {
                continue;
            }
            $items[] = ['name' => (string) $item->name_snapshot, 'size' => (string) ($item->size_snapshot ?? ''), 'qty' => (int) $item->quantity, 'child' => $child];
        }

        return [
            'orderNumber' => $order->order_number,
            'customerName' => (string) ($customer['fullName'] ?? ''),
            'phone' => (string) ($customer['phoneDisplay'] ?? $customer['phoneE164'] ?? ''),
            'email' => (string) ($customer['email'] ?? ''),
            'totalMinor' => (int) $order->total_paid_minor,
            'paymentMethod' => (string) $order->payment_method,
            'deliveryFeeNote' => (string) ($order->delivery_fee_note ?? ''),
            'area' => (string) ($delivery['area'] ?? ''),
            'items' => $items,
            'delivery' => [
                'address' => (string) ($delivery['address'] ?? ''), 'landmark' => (string) ($delivery['landmark'] ?? ''),
                'area' => (string) ($delivery['area'] ?? ''), 'region' => (string) ($delivery['region'] ?? ''),
                'ghanaPostGps' => (string) ($delivery['ghanaPostGps'] ?? ''),
            ],
        ];
    }

    /** First word of the customer's name for SMS greetings; '' when unknown. */
    protected function customerFirstName(Order $order): string
    {
        $name = trim((string) (((array) ($order->customer_snapshot_json ?? []))['fullName'] ?? ''));

        return $name === '' ? '' : (preg_split('/\s+/u', $name)[0] ?? '');
    }

    protected function customerEmail(Order $order): ?string
    {
        $email = ((array) ($order->customer_snapshot_json ?? []))['email'] ?? null;

        return is_string($email) ? $email : null;
    }

    /** Normalised customer mobile from the frozen snapshot; null (logged without the raw value) when missing or invalid. */
    protected function customerPhone(Order $order): ?string
    {
        $raw = ((array) ($order->customer_snapshot_json ?? []))['phoneE164'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            return RecipientIdentity::normalise(NotificationChannel::SMS, $raw);
        } catch (\Throwable) {
            Log::warning('Notification skipped: invalid customer phone.');

            return null;
        }
    }
}
