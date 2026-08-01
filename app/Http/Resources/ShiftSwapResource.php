<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 換班紀錄 API 回傳格式
 *
 * @property int    $id
 * @property int    $requester_id
 * @property int    $target_id
 * @property int    $status          狀態（0=待確認, 1=已同意, 2=已拒絕）
 * @property string $created_at
 */
class ShiftSwapResource extends JsonResource
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
            'id'                     => $this->id,
            'requester_id'           => $this->requester_id,
            'target_id'              => $this->target_id,
            'status'                 => $this->status,
            'requester'              => new AccountResource($this->whenLoaded('requester')),
            'target'                 => new AccountResource($this->whenLoaded('target')),
            'requester_assignment'   => new ShiftAssignmentResource($this->whenLoaded('requesterAssignment')),
            'target_assignment'      => new ShiftAssignmentResource($this->whenLoaded('targetAssignment')),
            'created_at'             => \Carbon\Carbon::parse($this->created_at)->toDateTimeString(),
        ];
    }
}
