<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Telegram 群組成員 API 回傳格式
 *
 * 忽略名單與「發言過的人」清單共用。
 */
class GroupMemberResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'           => $this->id,
            'username'     => $this->username,
            'display_name' => $this->display_name,
            'ignored'      => (bool) $this->ignored,
            // 手動加入的沒有發言紀錄，UI 要標出來（填錯 username 不會有錯誤訊息，只是不生效）
            'is_manual'    => $this->isManual(),
            'ignored_by'   => filled($this->ignored_by) && filled($this->ignoredBy)
                ? $this->ignoredBy->nickname
                : null,
            'ignored_at'   => filled($this->ignored_at)
                ? $this->ignored_at->toDateTimeString()
                : null,
            'last_seen_at' => filled($this->last_seen_at)
                ? $this->last_seen_at->toDateTimeString()
                : null,
        ];
    }
}
