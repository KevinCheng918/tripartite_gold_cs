<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新財務申請（繳款證明、補點）的私訊收件人
 */
class UpdateFinanceNoticeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            /*
             * 可以勾多位。全部取消勾選 ＝ 不通知任何人
             * （上傳與申請本身照樣成立，只是沒人會被私訊）。
             *
             * ⚠ exists 查的是 `user` 表（不是 `users`）—— 這個專案的使用者表
             * 沒有複數 s，寫錯會變成「資料表不存在」的 500。
             */
            'finance_notice_user_ids'   => 'nullable|array',
            'finance_notice_user_ids.*' => 'integer|exists:user,id',
        ];
    }

    public function messages()
    {
        return [
            'finance_notice_user_ids.array'     => trans('notification.msg.finance_user_not_found'),
            'finance_notice_user_ids.*.integer' => trans('notification.msg.finance_user_not_found'),
            'finance_notice_user_ids.*.exists'  => trans('notification.msg.finance_user_not_found'),
        ];
    }
}
