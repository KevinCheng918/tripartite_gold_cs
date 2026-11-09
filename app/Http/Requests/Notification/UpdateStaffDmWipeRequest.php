<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新私訊自動清除的設定
 *
 * ⚠⚠ **`keep_hours` 的上限必須小於 Telegram 的 48 小時窗口。**
 * 填 48 以上的話，排程撈出來的訊息永遠都已經過期 ——
 * **自動清除看起來開著，卻一則都清不掉而且不報錯**。
 */
class UpdateStaffDmWipeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $min = (int) config('constants.STAFF_DM_WIPE.MIN_KEEP_HOURS');
        $max = (int) config('constants.STAFF_DM_WIPE.MAX_KEEP_HOURS');

        return [
            // checkbox 沒勾時整個欄位不會送上來，所以是 nullable 而不是 required
            'auto_enabled' => 'nullable|boolean',
            'keep_hours'   => "required|integer|min:{$min}|max:{$max}",
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages()
    {
        $params = [
            'min' => (int) config('constants.STAFF_DM_WIPE.MIN_KEEP_HOURS'),
            'max' => (int) config('constants.STAFF_DM_WIPE.MAX_KEEP_HOURS'),
        ];

        return [
            'keep_hours.required' => trans('notification.msg.dm_wipe_hours_required'),
            'keep_hours.integer'  => trans('notification.msg.dm_wipe_hours_invalid', $params),
            'keep_hours.min'      => trans('notification.msg.dm_wipe_hours_invalid', $params),
            'keep_hours.max'      => trans('notification.msg.dm_wipe_hours_invalid', $params),
        ];
    }
}
