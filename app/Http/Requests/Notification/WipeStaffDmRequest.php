<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 清除某一位同仁的私訊
 *
 * ⚠ 這裡只擋「有沒有給一個存在的 user」。「他有沒有私訊可清」由
 * `StaffDmWipeService::wipeForUser()` 查名單時判斷 ——
 * 在這裡再寫一次條件只會變成兩份會各自走偏的規則。
 */
class WipeStaffDmRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'user_id' => 'required|integer|exists:user,id',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages()
    {
        return [
            'user_id.required' => trans('notification.msg.dm_wipe_no_user'),
            'user_id.integer'  => trans('notification.msg.dm_wipe_no_user'),
            'user_id.exists'   => trans('notification.msg.dm_wipe_no_user'),
        ];
    }
}
