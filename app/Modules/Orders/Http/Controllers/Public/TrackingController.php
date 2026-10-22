<?php

namespace App\Modules\Orders\Http\Controllers\Public;

use App\Modules\Orders\Actions\FindOrderForCustomer;
use App\Modules\Orders\Http\Requests\TrackOrderRequest;
use App\Modules\Orders\Http\Resources\CustomerOrderResource;
use App\Modules\Orders\Http\Resources\OrderConfirmationResource;
use App\Modules\Orders\Models\Order;

final class TrackingController
{
    public function track(TrackOrderRequest $request, FindOrderForCustomer $find): CustomerOrderResource
    {
        $order = $find->execute($request->string('orderNumber')->toString(), $request->string('phone')->toString());

        return new CustomerOrderResource($order->load(['items', 'statusEvents']));
    }

    public function confirmation(string $trackingToken, FindOrderForCustomer $find): OrderConfirmationResource
    {
        // Constant-shape 404; the token is a 32-char random capability.
        $order = strlen($trackingToken) >= 32
            ? Order::query()->where('tracking_token', $trackingToken)->first() : null;

        return new OrderConfirmationResource($order ?? $find->notFound());
    }
}
