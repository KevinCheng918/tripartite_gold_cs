<?php

namespace App\Http\Resources;

use App\Presenters\UserAgentPresenter;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

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
        /*
         * ⚠⚠ **解析在這裡做，前端不要自己解。**
         * 原本 `resources/views/admin/accounts/index.blade.php` 有一份 JS
         * `parseUA()` 自己用正規表達式拆 —— 而登入通知是在伺服器端發的，
         * 兩邊會各自維護一份規則、慢慢分岔。那份 JS 已經刪掉。
         *
         * 這個專案已經為「兩份各自維護」付過代價（`StaffResource` 與
         * `AccountResource` 各一份 `calcTenure()`，兩份同一個 bug）。
         */
        $parsed = UserAgentPresenter::parse($this->device);

        return [
            'id'          => $this->id,
            'user_id'     => $this->user_id,
            'account'     => $this->account,
            'ip'          => $this->ip,
            'is_success'  => $this->is_success,
            // 原字串還是給出去：查問題時要看得到完整 UA
            'device'      => $this->device,
            'os'          => Arr::get($parsed, 'os'),
            'browser'     => Arr::get($parsed, 'browser'),
            'device_type' => Arr::get($parsed, 'device'),
            'fail_reason' => $this->fail_reason,
            'created_at'  => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
