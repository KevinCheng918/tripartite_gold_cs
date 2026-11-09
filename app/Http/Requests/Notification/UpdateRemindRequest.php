<?php

namespace App\Http\Requests\Notification;

use App\Services\AppSettingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * 更新求助單超時提醒（首次／之後每隔／上限次數）
 */
class UpdateRemindRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'remind_first_minutes'    => 'required|integer|min:1|max:1440',
            'remind_interval_minutes' => 'required|integer|min:1|max:1440',

            /*
             * 提醒次數上限。
             *
             * min:1 —— 0 等於「開單後完全不提醒」，那跟關掉求助單提醒一樣，
             * 要關就把間隔設很大，不該用上限 0 來表達。
             * max:200 是防手滑：每分鐘跑一輪，上限太大就回到「深夜轟炸」。
             */
            'remind_max_count' => 'required|integer|min:1|max:200',
        ];
    }

    /**
     * 跨欄位驗證：上限不能低到讓逾時升級永遠不會發生
     *
     * ⚠⚠ 升級（打電話把當班人叫起來）掛在「催到第 N 次」上，而
     * `getTicketsDueForRemind()` 用 `remind_count <= $max` 收單 ——
     * 上限設得比升級門檻低的話，提醒會在升級之前就停止，**電話永遠不會響
     * 而且不會有任何錯誤**。
     *
     * ⚠ 這道**不能只擋在升級那一頁**：兩邊各自存檔，使用者可能先設好門檻
     * 10，之後才來這一頁把上限調成 8。所以兩邊都要擋
     * （另一邊見 `UpdateTicketEscalationRequest`）。
     *
     * ⚠ 升級功能**關著的時候不擋** —— 那時門檻沒有意義，擋下來只會讓人
     * 莫名其妙改不了一個跟他無關的設定。
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $settings = app(AppSettingService::class);

            if (!$settings->getBool(AppSettingService::KEY_ESCALATION_ENABLED)) {
                return;
            }

            $max = (int) Arr::get($this->validationData(), 'remind_max_count');
            $triggerAt = $settings->getInt(
                AppSettingService::KEY_ESCALATION_TRIGGER_AT,
                (int) config('constants.TICKET_ESCALATION.DEFAULT.TRIGGER_AT')
            );

            if ($max > 0 && $max <= $triggerAt) {
                $validator->errors()->add(
                    'remind_max_count',
                    trans('notification.msg.remind_max_below_escalation', ['trigger' => $triggerAt])
                );
            }
        });
    }

    public function messages()
    {
        return [
            'remind_first_minutes.required'    => trans('notification.msg.remind_required'),
            'remind_first_minutes.min'         => trans('notification.msg.remind_invalid'),
            'remind_first_minutes.max'         => trans('notification.msg.remind_invalid'),
            'remind_interval_minutes.required' => trans('notification.msg.remind_required'),
            'remind_interval_minutes.min'      => trans('notification.msg.remind_invalid'),
            'remind_interval_minutes.max'      => trans('notification.msg.remind_invalid'),
            'remind_max_count.required'        => trans('notification.msg.remind_max_required'),
            'remind_max_count.min'             => trans('notification.msg.remind_max_invalid'),
            'remind_max_count.max'             => trans('notification.msg.remind_max_invalid'),
        ];
    }
}
