<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Models\Order;
use App\Support\Http\ApiException;
use App\Support\PhoneNumber;

/** Order number + phone lookup. Every miss is the same generic 404 (product-spec §6.5, website-spec §14.4). */
final class FindOrderForCustomer
{
    public function execute(string $orderNumber, string $phone): Order
    {
        try {
            $e164 = PhoneNumber::fromGhanaInput($phone)->e164;
        } catch (\InvalidArgumentException) {
            $this->notFound();
        }

        $order = Order::query()->with('customer')->where('order_number', strtoupper(trim($orderNumber)))->first();
        if ($order === null || $order->customer->phone_e164 !== $e164) {
            $this->notFound();
        }

        return $order;
    }

    public function notFound(): never
    {
        throw new ApiException(404, 'ORDER_NOT_FOUND', 'We could not find an order matching those details.');
    }
}
