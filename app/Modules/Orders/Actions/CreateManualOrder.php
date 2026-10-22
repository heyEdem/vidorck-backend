<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Checkout\Actions\PriceCart;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Customers\Actions\UpsertCustomer;
use App\Modules\Notifications\Planners\OrderNotificationPlanner;
use App\Modules\Orders\Enums\OrderActorType;
use App\Modules\Orders\Enums\OrderSource;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Models\Order;
use App\Modules\Staff\Actions\AuditLogger;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiException;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * website-spec §15: already-paid order taken by phone/WhatsApp. Always enters at PAID (the Order model also refuses
 * any other creation status); items are snapshotted from the current catalogue through the checkout PriceCart.
 */
final class CreateManualOrder
{
    /** @param  array<string, mixed>  $input validated request data */
    public function execute(array $input, StaffUser $actor): Order
    {
        $priced = app(PriceCart::class)->price($input['items']);
        app(PriceCart::class)->applyParents($input['items'], $priced['lines']);
        $total = 0;
        foreach ($priced['lines'] as $line) {
            if ($line['lineTotalMinor'] > PHP_INT_MAX - $total) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'The order total is too large.', ['items' => ['Reduce the order total.']]);
            }
            $total += $line['lineTotalMinor'];
        }
        if ($total <= 0) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'The order total must be greater than zero.', ['items' => ['Select items with a payable price.']]);
        }
        // website-spec §15: the amount received must be what the catalogue says these items cost (delivery is billed separately).
        if ($input['amountPaidMinor'] !== $total) {
            throw new ApiException(422, 'AMOUNT_MISMATCH', 'The amount paid does not match the order total.', [
                'amountPaidMinor' => ["The items total {$total} pesewas."],
            ]);
        }

        $phone = PhoneNumber::fromGhanaInput((string) $input['phone']);
        $reference = trim((string) $input['paymentReference']);
        $paidAt = now();
        $customerSnapshot = ['fullName' => $input['fullName'], 'phoneE164' => $phone->e164, 'phoneDisplay' => $phone->display, 'email' => $input['email'] ?? null];
        $deliverySnapshot = [
            'region' => $input['region'], 'area' => app(CanonicalDeliveryArea::class)->resolve((string) $input['area']), 'address' => $input['address'],
            'landmark' => $input['landmark'] ?? null, 'ghanaPostGps' => $input['ghanaPostGps'] ?? null,
            'specialArrangement' => (bool) ($input['specialArrangement'] ?? false),
        ];

        try {
            $order = DB::transaction(function () use ($input, $actor, $priced, $total, $reference, $paidAt, $customerSnapshot, $deliverySnapshot): Order {
                $this->assertReferenceUnused($reference);
                $customer = app(UpsertCustomer::class)->execute($customerSnapshot, $total, $paidAt);
                $order = Order::query()->create([
                    'order_number' => app(NextOrderNumber::class)->execute((int) $paidAt->format('Y')),
                    'tracking_token' => Str::random(32), 'source' => OrderSource::MANUAL, 'customer_id' => $customer->id,
                    'checkout_attempt_id' => null, 'status' => OrderStatus::PAID,
                    'subtotal_minor' => $total, 'total_paid_minor' => $total, 'currency' => 'GHS',
                    'payment_method' => $input['paymentMethod'], 'payment_reference' => $reference,
                    'customer_snapshot_json' => $customerSnapshot, 'delivery_snapshot_json' => $deliverySnapshot,
                    'delivery_fee_note' => 'Delivery fee is arranged separately.',
                    'created_by_staff_id' => $actor->id, 'paid_at' => $paidAt,
                ]);
                app(InsertOrderItems::class)->execute($order, $priced['lines'], $total);
                // product-spec §6.8: the same reference may belong to an orderless online attempt; link it here.
                $attempt = CheckoutAttempt::query()->whereRaw('lower(paystack_reference) = ?', [mb_strtolower($reference)])
                    ->whereDoesntHave('order')->lockForUpdate()->first();
                if ($attempt !== null) {
                    app(LinkAttemptToOrder::class)->link($attempt, $order, $actor);
                }
                app(RecordOrderStatusEvent::class)->record($order, null, OrderStatus::PAID, OrderActorType::STAFF, $actor->id, 'Manual paid order created');
                // AuditLogger redacts any key containing "payment"; method and reference are recorded under neutral names.
                app(AuditLogger::class)->record($actor, 'order.manual.created', $order, ['status' => null], [
                    'status' => OrderStatus::PAID->value, 'orderNumber' => $order->order_number,
                    'source' => OrderSource::MANUAL->value, 'totalPaidMinor' => $total, 'currency' => 'GHS',
                    'method' => $input['paymentMethod'], 'reference' => $reference,
                    'customerPublicId' => $customer->public_id, 'itemCount' => count($priced['lines']),
                ]);
                if (is_string($input['note'] ?? null) && trim($input['note']) !== '') {
                    app(AddOrderNote::class)->execute($order, $actor, $input['note']);
                }
                app(OrderNotificationPlanner::class)->orderPaid($order);
                // Same "order entered PAID" signal as online orders (after commit); listeners may branch on `source`.
                Event::dispatch(new OrderPaid($order));

                return $order;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'payment_reference')) {
                throw $this->duplicate();
            }
            throw $e;
        }

        return $order;
    }

    /** Case-insensitive guard on top of the unique index, across online and manual orders. */
    private function assertReferenceUnused(string $reference): void
    {
        if (Order::query()->whereRaw('lower(payment_reference) = ?', [mb_strtolower($reference)])->exists()) {
            throw $this->duplicate();
        }
    }

    private function duplicate(): ApiException
    {
        return new ApiException(409, 'DUPLICATE_PAYMENT_REFERENCE', 'An order already exists for this payment reference.', [
            'paymentReference' => ['This payment reference has already been used.'],
        ]);
    }
}
