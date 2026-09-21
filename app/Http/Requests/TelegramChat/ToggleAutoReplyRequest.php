<?php

namespace App\Http\Requests\TelegramChat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 切換對話的自動回覆開關
 */
class ToggleAutoReplyRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'group_id' => 'required|integer',
            'enabled'  => 'required|boolean',
        ];
    }

    public function messages()
    {
        return [
            'group_id.required' => trans('telegram_chat.msg.group_not_found'),
            'enabled.required'  => trans('telegram_chat.msg.required'),
        ];
    }
}
