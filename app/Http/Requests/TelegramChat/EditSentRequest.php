<?php

namespace App\Http\Requests\TelegramChat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 改掉一則已經送出去的訊息
 *
 * ⚠ 這裡只驗「新內容」本身 —— 「這一則改不改得動」（是不是我方的、
 * 有沒有 `telegram_message_id`、是不是自動回覆、過沒過 12 小時）
 * 在 `TelegramMessage::canEdit()` 判斷：那是業務規則，
 * 而且錯的時候要給看得懂的訊息，不是一句 422。
 *
 * 詳見 .claude/knowledge/features/outbound-edit-recall.md
 */
class EditSentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            'content' => config('rules.TELEGRAM_EDIT_CONTENT'),
        ];
    }

    /**
     * @return array
     */
    public function messages()
    {
        return [
            'content.required' => trans('telegram_chat.msg.edit_content_required'),
            'content.max'      => trans('telegram_chat.msg.edit_content_max'),
        ];
    }
}
