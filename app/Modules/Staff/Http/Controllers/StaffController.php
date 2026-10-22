<?php

namespace App\Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Staff\Actions\ManageStaff;
use App\Modules\Staff\Http\Requests\CreateStaffRequest;
use App\Modules\Staff\Http\Requests\UpdateStaffRequest;
use App\Modules\Staff\Http\Resources\StaffResource;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class StaffController extends Controller
{
    public function index(Request $request): ApiResourceCollection
    {
        Gate::authorize('viewAny', StaffUser::class);
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'between:1,100']]);

        return new ApiResourceCollection(StaffUser::orderBy('id')->paginate($input['perPage'] ?? 15), StaffResource::class);
    }

    public function store(CreateStaffRequest $request, ManageStaff $action): StaffResource
    {
        /** @var StaffUser $actor */
        $actor = $request->user();
        $result = $action->create($actor, $request->validated());

        return (new StaffResource($result['staff']))->additional(['temporaryPassword' => $result['temporaryPassword']]);
    }

    public function update(UpdateStaffRequest $request, StaffUser $staff, ManageStaff $action): StaffResource
    {
        /** @var StaffUser $actor */
        $actor = $request->user();

        return new StaffResource($action->update($actor, $staff, $request->validated()));
    }

    public function resetPassword(Request $request, StaffUser $staff, ManageStaff $action): StaffResource
    {
        Gate::authorize('resetPassword', $staff);
        /** @var StaffUser $actor */
        $actor = $request->user();
        $result = $action->resetPassword($actor, $staff);

        return (new StaffResource($result['staff']))->additional(['temporaryPassword' => $result['temporaryPassword']]);
    }
}
