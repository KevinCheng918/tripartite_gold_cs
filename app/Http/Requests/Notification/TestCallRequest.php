<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 測試撥號：撥給哪一位同仁
 *
 * ⚠ 這裡**只擋「有沒有給一個存在的 user」**。「這個人撥不撥得出去」
 * （有沒有填 telegram_username）是 `NotificationSettingService::testCall()`
 * 在查名單時判斷的 —— 那份名單跟驗證規則得不出同一個答案，
 * 在這裡再寫一次只會變成兩份會各自走偏的條件。
 */
class TestCallRequest extends FormRequest
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
     * 三種失敗（沒給、不是數字、查不到）對使用者而言是同一件事：
     * 他在下拉選單裡選到的那個人不能用。所以共用同一句。
     *
     * @return array<string, string>
     */
    public function messages()
    {
        return [
            'user_id.required' => trans('notification.msg.call_test_no_user'),
            'user_id.integer'  => trans('notification.msg.call_test_no_user'),
            'user_id.exists'   => trans('notification.msg.call_test_no_user'),
        ];
    }
}
