<?php

namespace App\Http\Requests\PaymentConfig;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 站台餘點告警設定驗證
 *
 * 公版與門檻兩個值，存在 app_setting。
 *
 * 原本還有「冷卻天數」，已移除 —— 點數不足是持續狀態不是一次性事件，
 * 只要低於門檻就每天通知（見 [[2026-10-02-remove-credit-alert-cooldown]]）。
 */
class UpdateAlertSettingRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'alert_template' => 'required|string|max:' . config('rules.TELEGRAM_TEMPLATE_MAX'),

            /*
             * 點數是 decimal(15,2)，門檻用 numeric 才能跟它對得起來。
             *
             * min:0 允許填 0 —— 點數不可能小於 0，所以 0 等於
             * 「先不要告警」，是刻意留的關法（見 StationCreditAlertService::thresholdFor）
             */
            'threshold' => 'required|numeric|min:0|max:' . config('rules.STATION_CREDIT_MAX'),
        ];
    }

    public function messages()
    {
        return [
            'alert_template.required' => trans('payment_config.msg.alert_template_required'),
            'alert_template.max'      => trans('payment_config.msg.alert_template_max', [
                'value' => config('rules.TELEGRAM_TEMPLATE_MAX'),
            ]),
            'threshold.required'      => trans('payment_config.msg.alert_threshold_required'),
            'threshold.numeric'       => trans('payment_config.msg.alert_threshold_numeric'),
            'threshold.min'           => trans('payment_config.msg.alert_threshold_min'),
        ];
    }
}
