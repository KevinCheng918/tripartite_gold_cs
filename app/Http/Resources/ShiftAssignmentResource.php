<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 排班紀錄 API 回傳格式
 *
 * @property int    $id
 * @property int    $user_id
 * @property int    $shift_id
 * @property string $date
 * @property string $created_at
 */
class ShiftAssignmentResource extends JsonResource
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
            'id'         => $this->id,
            'user_id'    => $this->user_id,
            'shift_id'   => $this->shift_id,
            'date'       => optional($this->date)->format('Y-m-d'),
            'user'       => new AccountResource($this->whenLoaded('user')),
            'shift'      => new ShiftResource($this->whenLoaded('shift')),
            'created_at' => \Carbon\Carbon::parse($this->created_at)->toDateTimeString(),
        ];
    }
}
