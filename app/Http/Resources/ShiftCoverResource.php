<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 代班紀錄 API 回傳格式
 *
 * @property int         $id
 * @property int         $assignment_id
 * @property int         $requester_id
 * @property int         $cover_user_id
 * @property string      $cover_start
 * @property string      $cover_end
 * @property string|null $reason
 * @property int         $cover_user_status  代班人回應（0=待確認, 1=同意, 2=拒絕）
 * @property int         $admin_status       管理者審核（0=待審核, 1=核准, 2=駁回）
 * @property string      $created_at
 */
class ShiftCoverResource extends JsonResource
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
            'assignment_id'       => $this->assignment_id,
            'requester_id'        => $this->requester_id,
            'cover_user_id'       => $this->cover_user_id,
            'cover_start'         => $this->cover_start,
            'cover_end'           => $this->cover_end,
            'reason'              => $this->reason,
            'cover_user_status'   => $this->cover_user_status,
            'admin_status'        => $this->admin_status,
            'requester'           => new AccountResource($this->whenLoaded('requester')),
            'cover_user'          => new AccountResource($this->whenLoaded('coverUser')),
            'admin'               => new AccountResource($this->whenLoaded('admin')),
            'assignment'          => new ShiftAssignmentResource($this->whenLoaded('assignment')),
            'cover_user_responded_at' => $this->cover_user_responded_at
                ? \Carbon\Carbon::parse($this->cover_user_responded_at)->toDateTimeString() : null,
            'admin_responded_at'  => $this->admin_responded_at
                ? \Carbon\Carbon::parse($this->admin_responded_at)->toDateTimeString() : null,
            'created_at'          => \Carbon\Carbon::parse($this->created_at)->toDateTimeString(),
        ];
    }
}
