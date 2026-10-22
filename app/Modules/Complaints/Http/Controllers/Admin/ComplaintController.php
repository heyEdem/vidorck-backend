<?php

namespace App\Modules\Complaints\Http\Controllers\Admin;

use App\Modules\Complaints\Actions\AddComplaintNote;
use App\Modules\Complaints\Actions\ChangeComplaintStatus;
use App\Modules\Complaints\Actions\ClaimComplaint;
use App\Modules\Complaints\Actions\ListComplaints;
use App\Modules\Complaints\Http\Requests\AdminComplaintIndexRequest;
use App\Modules\Complaints\Http\Requests\ComplaintNoteRequest;
use App\Modules\Complaints\Http\Requests\ComplaintStatusRequest;
use App\Modules\Complaints\Http\Resources\ComplaintDetailResource;
use App\Modules\Complaints\Http\Resources\ComplaintListResource;
use App\Modules\Complaints\Models\Complaint;
use App\Modules\Staff\Models\StaffUser;
use App\Support\Http\ApiResourceCollection;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

final class ComplaintController
{
    public function index(AdminComplaintIndexRequest $request, ListComplaints $action): ApiResourceCollection
    {
        return new ApiResourceCollection($action->execute($request->validated(), $this->staff($request->user())), ComplaintListResource::class);
    }

    public function show(string $reference): ComplaintDetailResource
    {
        return new ComplaintDetailResource($this->find($reference));
    }

    public function claim(string $reference, Request $request, ClaimComplaint $action): ComplaintDetailResource
    {
        return $this->detail($action->execute($this->find($reference), $this->staff($request->user())));
    }

    public function note(string $reference, ComplaintNoteRequest $request, AddComplaintNote $action): ComplaintDetailResource
    {
        return $this->detail($action->execute($this->find($reference), $this->staff($request->user()), $request->string('body')->toString()));
    }

    public function status(string $reference, ComplaintStatusRequest $request, ChangeComplaintStatus $action): ComplaintDetailResource
    {
        return $this->detail($action->execute($this->find($reference), $this->staff($request->user()), $request->string('toStatus')->toString(), $request->filled('note') ? $request->string('note')->toString() : null));
    }

    private function find(string $reference): Complaint
    {
        return Complaint::query()->where('reference', $reference)->firstOrFail()->load(['assignedStaff', 'customer', 'order', 'events.staff']);
    }

    private function detail(Complaint $complaint): ComplaintDetailResource
    {
        return new ComplaintDetailResource($complaint->load(['assignedStaff', 'customer', 'order', 'events.staff']));
    }

    private function staff(mixed $user): StaffUser
    {
        return $user instanceof StaffUser ? $user : throw new AuthenticationException;
    }
}
