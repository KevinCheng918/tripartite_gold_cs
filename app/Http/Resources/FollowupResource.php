<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 待追蹤事項的列表回傳格式
 *
 * ⚠ **狀態與來源的文字在這裡翻好再送出去**（`*_label` / `*_icon`）：
 * 前端寫死 `status === 2 ? '已追問' : …` 的話，config 改了狀態定義
 * 兩邊就會不一致，而且前端的那份不會報錯。
 *
 * ⚠ 只挑要顯示的欄位 —— 整個 Model 丟進 JSON 會把 `ask_message_id`
 * 這類內部欄位一起送到前端。
 *
 * @property int         $id
 * @property int         $telegram_group_id
 * @property int         $source
 * @property string|null $sender_name
 * @property string|null $wait_text
 * @property string|null $matched_keyword
 * @property int         $status
 * @property int         $wait_count
 * @property int         $ask_round
 * @property int         $remind_count
 */
class FollowupResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'           => $this->id,
            'group_id'     => $this->telegram_group_id,
            'group_title'  => filled($this->group) ? $this->group->title : "#{$this->telegram_group_id}",
            'source'       => $this->source,
            'source_label' => (string) config("constants.FOLLOWUP.SOURCE_LABEL.{$this->source}"),
            'sender_name'  => $this->sender_name,
            'wait_text'    => $this->wait_text,
            'keyword'      => $this->matched_keyword,
            'status'       => $this->status,
            'status_label' => (string) config("constants.FOLLOWUP.STATUS_LABEL.{$this->status}"),
            'status_icon'  => (string) config("constants.FOLLOWUP.STATUS_ICON.{$this->status}"),
            // 還活著的才給操作按鈕 —— 由後端判斷，前端不要自己比對狀態數字
            'can_decide'   => !$this->isClosed(),
            'wait_count'   => (int) $this->wait_count,
            'ask_round'    => (int) $this->ask_round,
            'remind_count' => (int) $this->remind_count,
            // 前端用 window.formatDateTime() 轉時區，所以給字串而不是 ISO
            'wait_at'      => filled($this->wait_at) ? $this->wait_at->format('Y-m-d H:i:s') : null,
            'decided_by'   => filled($this->decider) ? $this->decider->nickname : null,
            'decided_at'   => filled($this->decided_at) ? $this->decided_at->format('Y-m-d H:i:s') : null,
            'created_at'   => filled($this->created_at) ? $this->created_at->format('Y-m-d H:i:s') : null,
        ];
    }
}
