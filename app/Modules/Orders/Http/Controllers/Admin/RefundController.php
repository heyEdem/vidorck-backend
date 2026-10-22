<?php

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Modules\Orders\Actions\AddRefundNote;
use App\Modules\Orders\Actions\CompleteManualRefund;
use App\Modules\Orders\Actions\DecideRefund;
use App\Modules\Orders\Actions\ListRefunds;
use App\Modules\Orders\Http\Requests\RefundDecisionRequest;
use App\Modules\Orders\Http\Requests\RefundIndexRequest;
use App\Modules\Orders\Http\Requests\RefundManualCompletionRequest;
use App\Modules\Orders\Http\Requests\RefundNoteRequest;
use App\Modules\Orders\Http\Requests\RefundRetryRequest;
use App\Modules\Orders\Http\Resources\AdminRefundResource;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiResourceCollection;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

final class RefundController
{
    public function index(RefundIndexRequest $request, ListRefunds $refunds): ApiResourceCollection
    {
        return new ApiResourceCollection($refunds->execute($request->validated()), AdminRefundResource::class);
    }

    public function show(int $id): AdminRefundResource
    {
        return $this->resource($id);
    }

    public function note(int $id, RefundNoteRequest $request, AddRefundNote $action): JsonResponse
    {
        $note = $action->execute(RefundRequest::query()->findOrFail($id), $this->staff($request->user()), $request->string('body')->toString());
        $note->load('staff');

        return response()->json(['data' => [
            'id' => $note->id, 'body' => $note->body, 'staffId' => $note->staff_id,
            'staffName' => $note->staff->name, 'createdAt' => $note->created_at->toIso8601String(),
        ]], 201);
    }

    public function decision(int $id, RefundDecisionRequest $request, DecideRefund $action): AdminRefundResource
    {
        $action->execute($id, $this->staff($request->user()), $request->string('decision')->toString(),
            $request->filled('reason') ? $request->string('reason')->toString() : null);

        return $this->resource($id);
    }

    public function retry(int $id, RefundRetryRequest $request, DecideRefund $action): AdminRefundResource
    {
        $action->retry($id, $this->staff($request->user()));

        return $this->resource($id);
    }

    public function manualCompletion(int $id, RefundManualCompletionRequest $request, CompleteManualRefund $action): AdminRefundResource
    {
        $action->execute($id, $this->staff($request->user()), $request->string('method')->toString(),
            $request->string('reference')->toString(), $request->integer('amountMinor'));

        return $this->resource($id);
    }

    private function resource(int $id): AdminRefundResource
    {
        return new AdminRefundResource(RefundRequest::query()->with(['order', 'transactions', 'notes.staff'])->findOrFail($id));
    }

    private function staff(mixed $user): StaffUser
    {
        return $user instanceof StaffUser ? $user : throw new AuthenticationException;
    }
}
