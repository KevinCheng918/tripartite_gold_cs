<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新每日超時提醒統計的收件人
 */
class UpdateReportRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // 可以勾多位。全部取消勾選 = 暫時不發統計
            'remind_report_user_ids'   => 'nullable|array',
            'remind_report_user_ids.*' => 'integer|exists:user,id',
        ];
    }

    public function messages()
    {
        return [
            'remind_report_user_ids.array'     => trans('notification.msg.report_user_not_found'),
            'remind_report_user_ids.*.integer' => trans('notification.msg.report_user_not_found'),
            'remind_report_user_ids.*.exists'  => trans('notification.msg.report_user_not_found'),
        ];
    }
}
