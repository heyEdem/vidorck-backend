<?php

namespace App\Modules\Checkout\Http\Controllers\Admin;

use App\Modules\Checkout\Actions\ListReconciliationAttempts;
use App\Modules\Checkout\Http\Requests\AdminReconciliationIndexRequest;
use App\Modules\Checkout\Http\Resources\Admin\ReconciliationAttemptResource;
use App\Support\Http\ApiResourceCollection;

final class ReconciliationController
{
    public function index(AdminReconciliationIndexRequest $request, ListReconciliationAttempts $reconciliation): ApiResourceCollection
    {
        return new ApiResourceCollection($reconciliation->execute($request->validated()), ReconciliationAttemptResource::class);
    }
}
