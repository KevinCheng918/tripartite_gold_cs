<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 消耗品流水 API 回傳格式
 *
 * 一筆領用或使用。前端要顯示「10/02 使用 2 卡片 備註（王小明 登記）」，
 * 所以人名與登記者都展開成字串 —— 前端不該再去對照 id。
 */
class ConsumableRecordResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'        => $this->id,
            'user_id'   => (int) $this->user_id,
            'user_name' => filled($this->user) ? $this->user->nickname : '—',
            'item_name' => $this->item_name,
            'type'      => (int) $this->type,
            // 前端用它決定顯示「領用」還是「使用」，不用自己比常數
            'is_issue'  => $this->isIssue(),
            'quantity'  => (int) $this->quantity,
            // 日期只到天，不要時分秒 —— 這是自己填的日期不是時間戳
            'happened_at' => filled($this->happened_at) ? $this->happened_at->toDateString() : null,
            'note'        => $this->note,
            /*
             * 登記者的帳號被刪時 created_by 會是 null（nullOnDelete），
             * 顯示「已離職人員」—— 比照忽略名單的 ignored_by。
             */
            'created_by' => filled($this->creator)
                ? $this->creator->nickname
                : trans('staff_manage.consumable_deleted_user'),
        ];
    }
}
