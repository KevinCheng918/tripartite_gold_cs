<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * 「後台帳號一律不自動回覆」名單的 API 回傳格式
 *
 * 面板上每一列是一個勾選框：勾 = 不自動回覆（預設），取消 = 這個對話
 * 特別打開他。所以除了身分，還要回這個對話的放行狀態與是誰打開的。
 *
 * **不回 `telegram_user_id` 本身** —— 那串數字對客服沒有意義，
 * 要知道的只是「這個人認得出來嗎」。
 *
 * 放行資訊靠外部傳入的 `$allows`（`user_id => ['by' => ..., 'at' => ...]`），
 * 不在 Resource 裡查 DB —— 一列查一次就是 N+1。
 */
class StaffIgnoreResource extends JsonResource
{
    /** @var array user_id => ['by' => string|null, 'at' => string|null] */
    private $allows;

    /**
     * @param mixed $resource
     * @param array $allows 這個對話的放行清單
     */
    public function __construct($resource, $allows = [])
    {
        parent::__construct($resource);

        $this->allows = $allows;
    }

    /**
     * 整批建立時把放行清單傳進每一列
     *
     * `collection()` 的預設實作不會帶額外參數，所以自己組 —— 否則每一列
     * 都拿不到 `$allows`，勾選狀態會全部變成預設值。
     *
     * @param mixed $resource
     * @param array $allows
     * @return \Illuminate\Support\Collection
     */
    public static function collectionWithAllows($resource, $allows = [])
    {
        return collect($resource)->map(function ($item) use ($allows) {
            return new static($item, $allows);
        });
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        $allow = Arr::get($this->allows, (int) $this->id);

        return [
            'id'       => $this->id,
            'nickname' => $this->nickname,
            // 存的時候已經去過 @，顯示時才補上去
            'username' => filled($this->telegram_username)
                ? '@' . ltrim($this->telegram_username, '@')
                : null,
            /*
             * 已經回填過 Telegram ID 的人，之後改掉 username 也認得出來。
             * UI 用這個標指紋圖示，讓客服知道哪些人最穩。
             */
            'has_user_id' => filled($this->telegram_user_id),

            /*
             * allowed = 這個對話特別打開了他（會被自動回覆）。
             * 前端的勾選框是反向的：allowed 為 true 時不勾。
             */
            'allowed'    => filled($allow),
            'allowed_by' => Arr::get($allow, 'by'),
            'allowed_at' => Arr::get($allow, 'at'),
        ];
    }
}
