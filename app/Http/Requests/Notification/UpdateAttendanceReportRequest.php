<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新打卡週報表／月報表的收件人
 */
class UpdateAttendanceReportRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            /*
             * 可以勾多位，週報與月報共用這一份名單。
             * 全部取消勾選 = 不發完整報表（個人版不受影響，那是發給每個人的）。
             *
             * ⚠ exists 查的是 `user` 表（不是 `users`）—— 這個專案的使用者表
             * 沒有複數 s，寫錯會變成「資料表不存在」的 500。
             */
            'attendance_report_user_ids'   => 'nullable|array',
            'attendance_report_user_ids.*' => 'integer|exists:user,id',
        ];
    }

    public function messages()
    {
        return [
            'attendance_report_user_ids.array'     => trans('notification.msg.attendance_user_not_found'),
            'attendance_report_user_ids.*.integer' => trans('notification.msg.attendance_user_not_found'),
            'attendance_report_user_ids.*.exists'  => trans('notification.msg.attendance_user_not_found'),
        ];
    }
}
