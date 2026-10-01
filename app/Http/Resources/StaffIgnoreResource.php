<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 「後台帳號一律不自動回覆」名單的 API 回傳格式
 *
 * 面板上是唯讀清單，所以只給看得到的東西：
 * 暱稱、Telegram 帳號、以及系統有沒有記到這個人的 Telegram ID。
 *
 * **不回 `telegram_user_id` 本身** —— 那串數字對客服沒有意義，
 * 要知道的只是「這個人認得出來嗎」。
 */
class StaffIgnoreResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'       => $this->id,
            'nickname' => $this->nickname,
            // 存的時候已經去過 @，顯示時才補上去
            'username' => filled($this->telegram_username)
                ? '@' . ltrim($this->telegram_username, '@')
                : null,
            /*
             * 已經回填過 Telegram ID 的人，之後改掉 username 也認得出來。
             * UI 用這個標「永久識別」，讓客服知道哪些人最穩。
             */
            'has_user_id' => filled($this->telegram_user_id),
        ];
    }
}
