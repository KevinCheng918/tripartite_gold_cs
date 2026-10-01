<?php

namespace App\Http\Requests\TelegramChat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 切換對話成員的「不自動回覆」狀態
 *
 * 五種動作共用這一支。前三個操作的是這個對話的忽略名單（名冊上的人）：
 * - ignore  從名冊上挑人（帶 member_id）
 * - add     用 username 手動加入（帶 username，可附備註）
 * - restore 恢復自動回覆（帶 member_id）
 *
 * 後兩個操作的是內部員工在這個對話的例外，**帶 user_id（後台帳號）而不是
 * member_id** —— 那份名單跟著 `user` 表走，與名冊無關：
 * - staff_allow 特別打開（這個對話會自動回覆他）
 * - staff_block 收回放行
 */
class ToggleIgnoreMemberRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $actions = config('constants.TELEGRAM.IGNORE.ACTION');
        $allowed = implode(',', array_values($actions));

        return [
            'group_id'  => 'required|integer',
            'action'    => "required|string|in:{$allowed}",
            // 挑人與恢復都是對名冊上既有的那一筆操作
            'member_id' => "required_if:action,{$actions['IGNORE']},{$actions['RESTORE']}|nullable|integer",
            // 手動加入沒有 member_id，只有對方的 username
            'username'  => [
                "required_if:action,{$actions['ADD']}",
                'nullable',
                'string',
                config('rules.TELEGRAM_USERNAME_REGEX'),
            ],
            'note'      => 'nullable|string|max:255',
            // 內部員工的例外以後台帳號為鍵；exists 擋掉前端送來不存在的 id
            'user_id'   => "required_if:action,{$actions['STAFF_ALLOW']},{$actions['STAFF_BLOCK']}|nullable|integer|exists:user,id",
        ];
    }

    public function messages()
    {
        return [
            'group_id.required'     => trans('telegram_chat.msg.group_not_found'),
            'action.required'       => trans('telegram_chat.msg.required'),
            'action.in'             => trans('telegram_chat.msg.required'),
            'member_id.required_if' => trans('telegram_chat.msg.member_not_found'),
            'username.required_if'  => trans('telegram_chat.msg.username_required'),
            'username.regex'        => trans('telegram_chat.msg.username_invalid'),
            'note.max'              => trans('telegram_chat.msg.note_too_long'),
            'user_id.required_if'   => trans('telegram_chat.msg.staff_not_found'),
            'user_id.exists'        => trans('telegram_chat.msg.staff_not_found'),
        ];
    }
}
