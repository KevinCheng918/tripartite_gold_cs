<?php

namespace App\Http\Requests\TelegramChat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Telegram 回覆訊息驗證
 */
class ReplyRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'group_id' => 'required|integer|exists:telegram_group,id',
            'content'  => 'required|string|max:4096',
            // 引用的訊息 id（後台的），Service 會換算成 Telegram 的 message_id
            'reply_to_id' => 'nullable|integer|exists:telegram_message,id',
        ];
    }

    public function messages()
    {
        return [
            'group_id.required' => trans('telegram_chat.msg.required'),
            'group_id.exists'   => trans('telegram_chat.msg.group_not_found'),
            'content.required'  => trans('telegram_chat.msg.required'),
            'content.max'       => trans('telegram_chat.msg.content_too_long'),
        ];
    }
}
