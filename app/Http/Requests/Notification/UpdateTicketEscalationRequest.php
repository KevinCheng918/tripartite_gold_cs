<?php

namespace App\Http\Requests\Notification;

use App\Services\AppSettingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * 更新求助單逾時升級的設定
 *
 * ⚠⚠ **這支存在的主要理由是那個跨欄位驗證**：升級門檻必須小於求助單的
 * 「最多提醒幾次」，否則提醒會在升級之前就停止 —— 電話永遠不會響，
 * **而且不會有任何錯誤訊息**。那是最難被發現的壞法。
 */
class UpdateTicketEscalationRequest extends FormRequest
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
             * 催到第幾次開始升級。
             *
             * min:2 —— 第 1 次就打電話等於取消了「先在群組 tag」那一層，
             * 而那一層本來就擋掉大部分情況。
             */
            'trigger_at' => 'required|integer|min:2|max:200',

            /*
             * 響幾秒。
             *
             * max:90 是 Telegram 自己的 ring timeout（`call_ring_timeout_ms`），
             * 填更大也不會響更久。min:5 是防手滑 —— 響 1 秒等於沒響。
             */
            'ring_seconds' => 'required|integer|min:5|max:90',

            /*
             * 兩通之間隔幾分鐘。
             *
             * ⚠ min:3 不是防呆：**連撥是 Telegram 判定 spam 的主要行為樣態**，
             * 間隔太短會讓我們自己的 userbot 帳號被封。
             */
            'call_interval' => 'required|integer|min:3|max:60',

            'manager_user_ids'   => 'nullable|array',
            'manager_user_ids.*' => 'integer|exists:user,id',
        ];
    }

    /**
     * 跨欄位驗證：升級門檻必須小於提醒次數上限
     *
     * ⚠ 這道**不能只靠前端**。上限是在「求助單提醒」那個分頁改的，兩邊
     * 各自存檔 —— 使用者可能先在這裡設門檻 10，之後才去另一頁把上限調成 8。
     * 所以兩邊都要擋（另一邊見 `UpdateRemindRequest`）。
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $triggerAt = (int) Arr::get($this->validationData(), 'trigger_at');

            if ($triggerAt < 1) {
                return;
            }

            $max = app(AppSettingService::class)->getInt(
                AppSettingService::KEY_REMIND_MAX_COUNT,
                (int) config('constants.AUTO_REPLY.REMIND.MAX_COUNT')
            );

            if ($triggerAt >= $max) {
                $validator->errors()->add(
                    'trigger_at',
                    trans('notification.msg.escalation_trigger_too_high', ['max' => $max])
                );
            }
        });
    }

    public function messages()
    {
        return [
            'trigger_at.required'    => trans('notification.msg.escalation_trigger_required'),
            'trigger_at.integer'     => trans('notification.msg.escalation_trigger_invalid'),
            'trigger_at.min'         => trans('notification.msg.escalation_trigger_invalid'),
            'trigger_at.max'         => trans('notification.msg.escalation_trigger_invalid'),
            'ring_seconds.required'  => trans('notification.msg.escalation_ring_required'),
            'ring_seconds.integer'   => trans('notification.msg.escalation_ring_invalid'),
            'ring_seconds.min'       => trans('notification.msg.escalation_ring_invalid'),
            'ring_seconds.max'       => trans('notification.msg.escalation_ring_invalid'),
            'call_interval.required' => trans('notification.msg.escalation_interval_required'),
            'call_interval.integer'  => trans('notification.msg.escalation_interval_invalid'),
            'call_interval.min'      => trans('notification.msg.escalation_interval_invalid'),
            'call_interval.max'      => trans('notification.msg.escalation_interval_invalid'),
        ];
    }
}
