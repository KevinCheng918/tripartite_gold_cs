<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新待追蹤事項的設定
 *
 * 開關、等待詞、兩個間隔、上限次數、自動收單天數、統計收件人。
 */
class UpdateFollowupRequest extends FormRequest
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
             * 等待詞，一行一個。留白是合法的 —— 那表示「用系統預設那一組」。
             * 上限 2000 字是防手滑貼進一整篇文章（每一行都要跑一次字串比對）。
             */
            'keywords' => 'nullable|string|max:2000',

            'quiet_minutes'    => 'required|integer|min:1|max:1440',
            'interval_minutes' => 'required|integer|min:1|max:1440',

            /*
             * 問當班人員的次數上限。
             *
             * min:1 —— 0 等於「記下來但永遠不問」，那跟關掉功能一樣，
             * 要關就把開關關掉，不該用上限 0 來表達。
             * max:200 是防手滑：每分鐘跑一輪，上限太大就回到「深夜轟炸」。
             */
            'max_count' => 'required|integer|min:1|max:200',

            /*
             * 幾天沒結果就能被 followup:close-stale 收掉。
             *
             * ⚠ **允許 0**，而且 0 是預設值 —— 需求方要「不自動結案」，
             * 0 就是「這支指令不給天數時什麼都不做」。這跟上面的 min:1 不衝突：
             * 那是「問幾次」，這是「要不要有自動收單這條路」。
             */
            'stale_days' => 'required|integer|min:0|max:365',

            'report_user_ids'   => 'nullable|array',
            'report_user_ids.*' => 'integer|exists:user,id',
        ];
    }

    public function messages()
    {
        return [
            'keywords.max'              => trans('notification.msg.followup_keywords_invalid'),
            'quiet_minutes.required'    => trans('notification.msg.followup_minutes_required'),
            'quiet_minutes.min'         => trans('notification.msg.followup_minutes_invalid'),
            'quiet_minutes.max'         => trans('notification.msg.followup_minutes_invalid'),
            'interval_minutes.required' => trans('notification.msg.followup_minutes_required'),
            'interval_minutes.min'      => trans('notification.msg.followup_minutes_invalid'),
            'interval_minutes.max'      => trans('notification.msg.followup_minutes_invalid'),
            'max_count.required'        => trans('notification.msg.followup_max_required'),
            'max_count.min'             => trans('notification.msg.followup_max_invalid'),
            'max_count.max'             => trans('notification.msg.followup_max_invalid'),
            'stale_days.required'       => trans('notification.msg.followup_stale_required'),
            'stale_days.min'            => trans('notification.msg.followup_stale_invalid'),
            'stale_days.max'            => trans('notification.msg.followup_stale_invalid'),
            'report_user_ids.*.exists'  => trans('notification.msg.report_user_not_found'),
        ];
    }
}
