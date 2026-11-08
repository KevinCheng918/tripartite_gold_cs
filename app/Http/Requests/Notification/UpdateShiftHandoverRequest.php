<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新班別交接的設定
 *
 * 總開關、提前幾分鐘開始提醒、沒交接時每隔幾分鐘再催。
 *
 * ⚠ **沒有「催幾次上限」** —— 需求方 2026-11-08 指定「就是催到他完成交接
 * 事項」，因為「他沒有就會打沒有了」。控制吵不吵的唯一旋鈕是間隔。
 */
class UpdateShiftHandoverRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // checkbox 沒勾時整個欄位不會送上來，所以是 nullable 而不是 required
            'enabled' => 'nullable|boolean',

            /*
             * 提前幾分鐘開始提醒。
             *
             * max 是**下一班回訊開始前**幾分鐘，填太大會在上一班還在忙的時候
             * 就開始催（交接窗口本身才一小時），所以壓在 60 分鐘。
             */
            'lead_minutes' => 'required|integer|min:1|max:60',

            /*
             * 沒交接時每隔幾分鐘再催。
             *
             * ⚠ min 刻意是 **5 而不是 1**：這個功能沒有次數上限，間隔 1 分鐘
             * 等於每分鐘 tag 一群人直到有人交接 —— 那不是提醒，是攻擊。
             */
            'interval_minutes' => 'required|integer|min:5|max:1440',
        ];
    }

    public function messages()
    {
        return [
            'lead_minutes.required'     => trans('notification.msg.handover_lead_required'),
            'lead_minutes.integer'      => trans('notification.msg.handover_lead_invalid'),
            'lead_minutes.min'          => trans('notification.msg.handover_lead_invalid'),
            'lead_minutes.max'          => trans('notification.msg.handover_lead_invalid'),
            'interval_minutes.required' => trans('notification.msg.handover_interval_required'),
            'interval_minutes.integer'  => trans('notification.msg.handover_interval_invalid'),
            'interval_minutes.min'      => trans('notification.msg.handover_interval_invalid'),
            'interval_minutes.max'      => trans('notification.msg.handover_interval_invalid'),
        ];
    }
}
