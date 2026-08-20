<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 登入紀錄 API 回傳格式
 *
 * @property int         $id
 * @property int|null    $user_id
 * @property string      $account
 * @property string      $ip
 * @property bool        $is_success
 * @property string|null $device
 * @property string|null $fail_reason
 * @property string      $created_at
 */
class LoginLogResource extends JsonResource
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
            'id'          => $this->id,
            'user_id'     => $this->user_id,
            'account'     => $this->account,
            'ip'          => $this->ip,
            'is_success'  => $this->is_success,
            'device'      => $this->device,
            'fail_reason' => $this->fail_reason,
            'created_at'  => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
