<?php

namespace App\Http\Resources;

use App\Presenters\UserPresenter;
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
            'hired_at'            => $this->hired_at ? $this->hired_at->format('Y-m-d') : null,
            /*
             * ⚠ 走共用的 `UserPresenter::tenureText()`。這裡原本有自己的
             * `calcTenure()`，跟 `StaffResource` 各算一份 —— 而兩份都用了
             * `DateTime::diff()` 的年月日拆解，在 Asia/Taipei 會跳號。
             */
            'tenure'              => UserPresenter::tenureText($this->hired_at),
            'equipments'          => $this->equipments ?? [],
            'permission_keywords' => $this->whenLoaded('permissions', function () {
                return $this->permissions->pluck('permission_keyword')->all();
            }),
            'created_at'          => \Carbon\Carbon::parse($this->created_at)->toDateTimeString(),
        ];
    }

}
