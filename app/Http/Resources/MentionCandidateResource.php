<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * 打 `@` 時的候選成員
 *
 * ⚠ `user_id` **只有「沒有 username」的人才會有值** —— 那種人要用
 * `tg://user?id=` 才叫得到（見 `TelegramMentionService`）。
 * 有 username 的人送 `@xxx` 就夠了，不把他的 Telegram id 散到前端。
 */
class MentionCandidateResource extends JsonResource
{
    /**
     * ⚠ 來源是陣列不是 Model（Service 已經組好），所以用 `Arr::get()`
     * 而不是屬性存取。
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'label'    => (string) Arr::get($this->resource, 'label'),
            'username' => Arr::get($this->resource, 'username'),
            'user_id'  => Arr::get($this->resource, 'user_id'),
            'is_staff' => (bool) Arr::get($this->resource, 'is_staff'),
        ];
    }
}
