<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * Telegram 訊息 API 回傳格式
 */
class TelegramMessageResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'                  => $this->id,
            'direction'           => $this->direction,
            'telegram_message_id' => $this->telegram_message_id,
            'sender_name'         => $this->sender_name,
            /*
             * ⚠ 前端要靠它判斷「這則是不是我送的」—— 自己送的不需要
             * `edit_others` 權限就能改。
             *
             * ⚠ 自動回覆送出的這一欄是 **null**，所以那種一定會落到
             * 「要有 edit_others 才動得了」那一邊 —— 這是對的，
             * AI 送的訊息不屬於任何一個人。
             */
            'sender_user_id'      => $this->sender_user_id,
            'content'             => $this->content,
            'is_auto'             => (bool) $this->is_auto,
            'is_edited'           => filled($this->edited_at),
            'media_type'          => $this->media_type,
            'media_url'           => $this->media_url,
            'media_name'          => $this->media_name,
            'reply_to_sender'     => $this->reply_to_sender,
            'reply_to_text'       => $this->reply_to_text,
            'reactions'           => $this->reactions,
            'replied'             => $this->replied,
            'created_at'          => \Carbon\Carbon::parse($this->created_at)->toDateTimeString(),

            /*
             * 改掉／撤回（需求方 2026-11-25）。
             *
             * ⚠⚠ **判斷放在 Model（`canEdit()`／`canRecall()`），不要在前端
             * 各算一份。** 前端只決定按鈕畫不畫得出來，真正擋下來的是後端 ——
             * 兩邊各寫一份條件，遲早會出現「看得到卻按不動」的按鈕
             * （[[leave-cancel]] 的 `can_cancel` 也是同一個原則）。
             *
             * ⚠ `can_edit` 只看那一則本身的條件（是不是我方人工送出的純文字、
             * 12 小時內）—— **不含「這個人有沒有權限動別人的」**，那一層在
             * Controller 判（`canTouchSent()`），因為它跟登入者有關、
             * 而 Resource 不該去碰 Auth。
             */
            'is_recalled' => $this->isRecalled(),
            'can_edit'    => $this->canEdit(),
            'can_recall'  => $this->canRecall(),

            /*
             * 誰改的、誰撤的（需求方 2026-11-25：「要標示誰已編輯／誰已收回」）。
             *
             * ⚠ `edited_by` 是**空的也正常** —— 客人自己在 Telegram 改自己
             * 那句話時，我們一樣會寫 `edited_at`，但沒有 `edited_by`。
             * 前端就是靠這個分辨「客人改的」還是「某位客服改的」。
             *
             * ⚠ 人離職被刪掉時關聯會是 null（FK 是 nullOnDelete），
             * 所以這裡要能接受 null，不要直接 `->nickname`。
             */
            'edited_by_name'   => Arr::get($this->editedByUser, 'nickname'),
            'recalled_by_name' => Arr::get($this->recalledByUser, 'nickname'),
        ];
    }
}
