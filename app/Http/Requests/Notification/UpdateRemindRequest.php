<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

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
