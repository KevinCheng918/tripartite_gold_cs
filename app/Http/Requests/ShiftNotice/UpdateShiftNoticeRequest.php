<?php

namespace App\Http\Requests\ShiftNotice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新班表通知設定
 */
class UpdateShiftNoticeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            /*
             * nullable：允許清空（暫時不要發給任何人）。
             *
             * exists 查的是 `user` 表（不是 `users`）—— 這個專案的使用者表
             * 沒有複數 s，寫錯會變成「資料表不存在」的 500。
             */
            'manager_user_id' => 'nullable|integer|exists:user,id',
        ];
    }

    public function messages()
    {
        return [
            'manager_user_id.integer' => trans('shift_notice.msg.manager_invalid'),
            'manager_user_id.exists'  => trans('shift_notice.msg.manager_not_found'),
        ];
    }
}
