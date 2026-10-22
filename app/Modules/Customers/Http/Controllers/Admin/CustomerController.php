<?php

namespace App\Modules\Customers\Http\Controllers\Admin;

use App\Modules\Customers\Actions\AddCustomerNote;
use App\Modules\Customers\Actions\ListCustomers;
use App\Modules\Customers\Http\Requests\AdminCustomerIndexRequest;
use App\Modules\Customers\Http\Requests\CustomerNoteRequest;
use App\Modules\Customers\Http\Resources\AdminCustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiResourceCollection;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class CustomerController
{
    public function index(AdminCustomerIndexRequest $request, ListCustomers $customers): ApiResourceCollection
    {
        return new ApiResourceCollection($customers->execute($request->validated()), AdminCustomerResource::class);
    }

    public function show(string $publicId): AdminCustomerResource
    {
        $customer = Customer::query()->where('public_id', $publicId)->firstOrFail();

        $relations = ['orders', 'notes.staff'];
        if (Gate::allows('view-complaints')) {
            $relations[] = 'complaints';
        }

        return new AdminCustomerResource($customer->load($relations));
    }

    public function note(string $publicId, CustomerNoteRequest $request, AddCustomerNote $action): JsonResponse
    {
        $user = $request->user();
        $customer = Customer::query()->where('public_id', $publicId)->firstOrFail();
        $note = $action->execute($customer, $user instanceof StaffUser ? $user : throw new AuthenticationException, $request->string('body')->toString());
        $note->load('staff');

        return response()->json(['data' => [
            'id' => $note->id, 'body' => $note->body, 'staffId' => $note->staff_id,
            'staffName' => $note->staff->name, 'createdAt' => $note->created_at->toIso8601String(),
        ]], 201);
    }
}
