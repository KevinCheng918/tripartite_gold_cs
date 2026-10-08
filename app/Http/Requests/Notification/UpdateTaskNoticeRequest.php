<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新任務卡通知的收件人
 */
class UpdateTaskNoticeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            /*
             * 可以勾多位。全部取消勾選 = 不發總覽（個人版不受影響，那是發給每個人的）。
             *
             * ⚠ exists 查的是 `user` 表（不是 `users`）—— 這個專案的使用者表
             * 沒有複數 s，寫錯會變成「資料表不存在」的 500。
             */
            'task_notice_user_ids'   => 'nullable|array',
            'task_notice_user_ids.*' => 'integer|exists:user,id',
        ];
    }

    public function messages()
    {
        return [
            'task_notice_user_ids.array'     => trans('notification.msg.task_user_not_found'),
            'task_notice_user_ids.*.integer' => trans('notification.msg.task_user_not_found'),
            'task_notice_user_ids.*.exists'  => trans('notification.msg.task_user_not_found'),
        ];
    }
}
