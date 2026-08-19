<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 打卡紀錄 API 回傳格式
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $date
 * @property string|null $clock_in
 * @property string|null $clock_out
 * @property int         $late_minutes
 * @property int         $early_leave_minutes
 * @property int         $overtime_minutes
 * @property int         $status
 */
class AttendanceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'                  => $this->id,
            'user_id'             => $this->user_id,
            'date'                => optional($this->date)->format('Y-m-d'),
            'clock_in'            => $this->clock_in ? \Carbon\Carbon::parse($this->clock_in)->toDateTimeString() : null,
            'clock_out'           => $this->clock_out ? \Carbon\Carbon::parse($this->clock_out)->toDateTimeString() : null,
            'clock_in_ip'         => $this->clock_in_ip,
            'clock_out_ip'        => $this->clock_out_ip,
            'clock_in_device'     => $this->clock_in_device,
            'clock_out_device'    => $this->clock_out_device,
            'late_minutes'        => $this->late_minutes,
            'early_leave_minutes' => $this->early_leave_minutes,
            'overtime_minutes'    => $this->overtime_minutes,
            'status'              => $this->status,
            'leave_info'          => $this->leave_info ?? null,
            'user'                => new AccountResource($this->whenLoaded('user')),
            'assignment'          => new ShiftAssignmentResource($this->whenLoaded('assignment')),
        ];
    }
}
