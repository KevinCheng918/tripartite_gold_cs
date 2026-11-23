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
            /*
             * ⚠⚠ **假別**（特休／病假／事假）。需求方 2026-11-24：
             * 「排班管理的請假管理，要標明是請哪一個假別。」
             *
             * ⚠ 這一欄一直存在 `leave_request.type`、repository 也撈了，
             * **只是從來沒放進這個 Resource** —— 所以前端根本拿不到，
             * 畫面上的「類型」欄顯示的是 `is_full_day`（整天／時段），
             * 那是另一件事。
             *
             * ⚠ 可能是 `unclassified`（改版前的舊資料），前端要有對應的字。
             */
            'type'        => $this->type,
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
