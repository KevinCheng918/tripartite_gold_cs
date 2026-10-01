<?php

namespace App\Http\Requests\DailyRate;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 後台手動設定某日匯率
 */
class UpdateRateRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            /*
             * 允許補登過去的日期（某天 Telegram 那邊沒人回，事後補），
             * 但不給填未來 —— 明天的匯率要等明天早上報。
             */
            'date' => 'required|date|before_or_equal:today',

            // 匯率不可能是 0 或負數；上限用點數那組共用的最大值
            'rate' => 'required|numeric|min:0.0001|max:' . config('rules.STATION_CREDIT_MAX'),
        ];
    }

    public function messages()
    {
        return [
            'date.required'        => trans('daily_rate.msg.date_required'),
            'date.before_or_equal' => trans('daily_rate.msg.date_future'),
            'rate.required'        => trans('daily_rate.msg.rate_required'),
            'rate.numeric'         => trans('daily_rate.msg.rate_numeric'),
            'rate.min'             => trans('daily_rate.msg.rate_min'),
        ];
    }
}
