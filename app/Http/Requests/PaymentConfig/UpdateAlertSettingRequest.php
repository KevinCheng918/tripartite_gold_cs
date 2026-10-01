<?php

namespace App\Http\Requests\PaymentConfig;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 站台餘點告警設定驗證
 *
 * 公版、門檻、冷卻天數三個值，存在 app_setting。
 */
class UpdateAlertSettingRequest extends FormRequest
{
    /** @var int 冷卻天數上限。設超過一年等於關掉告警，用 0 更直覺 */
    private const COOLDOWN_MAX = 365;

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

            // 0 = 每次跑到都發（不設冷卻）
            'cooldown_days' => 'required|integer|min:0|max:' . self::COOLDOWN_MAX,
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
            'cooldown_days.required'  => trans('payment_config.msg.alert_cooldown_required'),
            'cooldown_days.integer'   => trans('payment_config.msg.alert_cooldown_integer'),
            'cooldown_days.max'       => trans('payment_config.msg.alert_cooldown_max', ['value' => self::COOLDOWN_MAX]),
        ];
    }
}
