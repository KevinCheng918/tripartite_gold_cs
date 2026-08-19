<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 請假申請 Resource
 *
 * @mixin \App\Models\LeaveRequest
 */
class LeaveRequestResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'user'        => $this->user ? $this->user->nickname : '-',
            'user_id'     => $this->user_id,
            'start_date'  => $this->start_date ? $this->start_date->format('Y-m-d') : null,
            'end_date'    => $this->end_date ? $this->end_date->format('Y-m-d') : null,
            'is_full_day' => $this->is_full_day,
            'start_time'  => $this->start_time,
            'end_time'    => $this->end_time,
            'reason'      => $this->reason,
            'status'      => $this->status,
            'reviewer'    => $this->reviewer ? $this->reviewer->nickname : null,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at ? $this->reviewed_at->format('Y-m-d H:i') : null,
            'created_at'  => $this->created_at->format('Y-m-d H:i'),
        ];
    }
}
