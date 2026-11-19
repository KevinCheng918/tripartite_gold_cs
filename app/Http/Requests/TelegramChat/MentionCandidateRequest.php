<?php

namespace App\Http\Requests\TelegramChat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 打 `@` 時要候選名單
 *
 * ⚠ `keyword` 刻意**不套 username 的格式規則**：客服可能在打中文顯示名稱
 * （「權志」），而名冊的 `display_name` 本來就不是 username 格式。
 * 只限長度，避免有人塞一整篇進來當搜尋條件。
 */
class MentionCandidateRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'group_id' => 'required|integer',
            'keyword'  => 'nullable|string|max:' . config('rules.MENTION_KEYWORD_MAX'),
        ];
    }

    public function messages()
    {
        return [
            'group_id.required' => trans('telegram_chat.msg.group_not_found'),
            'group_id.integer'  => trans('telegram_chat.msg.group_not_found'),
            /*
             * ⚠ 不要借用「此欄位為必填」那一句 —— 這裡是**太長**，
             * 訊息文不對題比沒有訊息更難查。
             */
            'keyword.max'       => trans('telegram_chat.msg.mention_keyword_too_long'),
        ];
    }
}
