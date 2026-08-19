<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 帳號 API 回傳格式
 *
 * @property int    $id
 * @property string $account
 * @property string $nickname
 * @property int    $status
 * @property int    $level
 * @property string $created_at
 */
class AccountResource extends JsonResource
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
            'account'             => $this->account,
            'nickname'            => $this->nickname,
            'status'              => $this->status,
            'level'               => $this->level,
            'project_ids'         => $this->project_ids ?? [],
            'permission_keywords' => $this->whenLoaded('permissions', function () {
                return $this->permissions->pluck('permission_keyword')->all();
            }),
            'created_at'          => \Carbon\Carbon::parse($this->created_at)->toDateTimeString(),
        ];
    }
}
