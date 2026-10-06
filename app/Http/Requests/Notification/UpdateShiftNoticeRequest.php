<?php

namespace App\Http\Requests\Notification;

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
             * 可以勾多位（含全選）。nullable + array：全部取消勾選代表
             * 「暫時不要發給任何人」。
             *
             * ⚠ exists 查的是 `user` 表（不是 `users`）—— 這個專案的使用者表
             * 沒有複數 s，寫錯會變成「資料表不存在」的 500。
             */
            'manager_user_ids'   => 'nullable|array',
            'manager_user_ids.*' => 'integer|exists:user,id',
        ];
    }

    public function messages()
    {
        return [
            'manager_user_ids.array'      => trans('notification.msg.manager_invalid'),
            'manager_user_ids.*.integer'  => trans('notification.msg.manager_invalid'),
            'manager_user_ids.*.exists'   => trans('notification.msg.manager_not_found'),
        ];
    }
}
