<?php

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Actions\AddOrderNote;
use App\Modules\Orders\Actions\ListOrders;
use App\Modules\Orders\Actions\UpdateOrderContents;
use App\Modules\Orders\Actions\UpdateOrderDelivery;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Http\Requests\AdminOrderIndexRequest;
use App\Modules\Orders\Http\Requests\OrderContentsUpdateRequest;
use App\Modules\Orders\Http\Requests\OrderDeliveryUpdateRequest;
use App\Modules\Orders\Http\Requests\OrderNoteRequest;
use App\Modules\Orders\Http\Requests\OrderTransitionRequest;
use App\Modules\Orders\Http\Resources\AdminOrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\OrderStateMachine;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiResourceCollection;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class OrderController
{
    public function index(AdminOrderIndexRequest $request, ListOrders $orders): ApiResourceCollection
    {
        return new ApiResourceCollection($orders->execute($request->validated()), AdminOrderResource::class);
    }

    public function show(string $number): AdminOrderResource
    {
        return $this->resource($this->find($number));
    }

    public function transition(string $number, OrderTransitionRequest $request, OrderStateMachine $machine): AdminOrderResource
    {
        $order = $machine->transition(
            $this->find($number),
            OrderStatus::from($request->string('toStatus')->toString()),
            $this->staff($request->user()),
            $request->filled('reason') ? $request->string('reason')->toString() : null,
        );

        return $this->resource($order);
    }

    public function note(string $number, OrderNoteRequest $request, AddOrderNote $action): JsonResponse
    {
        $note = $action->execute($this->find($number), $this->staff($request->user()), $request->string('body')->toString());
        $note->load('staff');

        return response()->json(['data' => [
            'id' => $note->id, 'body' => $note->body, 'staffId' => $note->staff_id,
            'staffName' => $note->staff->name, 'createdAt' => $note->created_at->toIso8601String(),
        ]], 201);
    }

    public function delivery(string $number, OrderDeliveryUpdateRequest $request, UpdateOrderDelivery $action): AdminOrderResource
    {
        $order = $action->execute($this->find($number), $this->staff($request->user()), $request->changes(), $request->string('reason')->toString());

        return $this->resource($order);
    }

    public function contents(string $number, OrderContentsUpdateRequest $request, UpdateOrderContents $action): AdminOrderResource
    {
        $order = $action->execute($this->find($number), $this->staff($request->user()), $request->items(),
            $request->string('reason')->toString(), $request->settlementMethod(), $request->settlementNote(), $request->integer('basedOnRevision'));

        return $this->resource($order);
    }

    private function find(string $number): Order
    {
        return Order::query()->where('order_number', strtoupper(trim($number)))->firstOrFail();
    }

    private function resource(Order $order): AdminOrderResource
    {
        $relations = ['customer', 'items.productVariant', 'statusEvents', 'notes.staff', 'contentRevisions.staff'];
        if (Gate::allows('view-complaints')) {
            $relations[] = 'complaints';
        }

        return new AdminOrderResource($order->load($relations));
    }

    private function staff(mixed $user): StaffUser
    {
        return $user instanceof StaffUser ? $user : throw new AuthenticationException;
    }
}
