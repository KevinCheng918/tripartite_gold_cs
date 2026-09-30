<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 申請補打卡驗證
 */
class RequestAmendRequest extends FormRequest
{
    /** @var int 原因的字數上限。規則與訊息都要用到，寫成常數才不會改一邊漏一邊 */
    private const REASON_MAX = 500;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            /*
             * 補的是「已經發生但沒打到」的卡，未來日期沒有意義 ——
             * 前端的 max 只是方便，擋不住直接打 API。
             *
             * ⚠ 日期選的是**實際打卡的那一天**。晚班下班在隔天凌晨，
             * 所以這裡會收到隔天的日期，由 ClockAmendmentService 往前找回
             * 對應的班次（見 features/attendance.md 的「跨日班的補打卡」）。
             */
            'date'       => 'required|date|before_or_equal:today',
            'type'       => 'required|integer|in:1,2',
            'clock_time' => 'required|date_format:H:i',
            // 必填：補打卡等於事後修改出勤紀錄，主管要據此核准
            'reason'     => 'required|string|max:' . self::REASON_MAX,
        ];
    }

    public function messages()
    {
        return [
            // 前端的 max 屬性擋得掉一般操作，這句是直接打 API 時才看得到的 ——
            // 但沒有它就會吐 Laravel 預設的英文訊息
            'date.before_or_equal' => trans('attendance.msg.amend_date_future'),
            'reason.required'      => trans('attendance.msg.amend_reason_required'),
            'reason.max'           => trans('attendance.msg.amend_reason_max', ['value' => self::REASON_MAX]),
        ];
    }
}
