<?php

namespace App\Modules\Complaints\Repositories;

use App\Modules\Complaints\Models\ComplaintEvent;

final class ComplaintEventRepository
{
    /** @param array{complaint_id:int,staff_id?:int|null,type:string,from_status?:string|null,to_status?:string|null,body?:string|null,created_at:\DateTimeInterface|string} $values */
    public function insert(array $values): ComplaintEvent
    {
        return ComplaintEvent::query()->create($values);
    }
}
