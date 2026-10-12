<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 更新某個帳號要收哪些私訊
 *
 * ⚠ 收的是「**要收**的那幾種」（畫面上勾起來的），不是要關掉的。
 * 反轉成黑名單由 Service 做 —— 前端送「使用者看到什麼就是什麼」，
 * 不必知道資料庫存的是相反的東西。
 *
 * ⚠ 全部取消勾選是合法的（他就是什麼都不想收），所以 `notices` 本身允許
 * 缺少或空陣列，不能用 `required`。
 */
class UpdateNoticeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'notices'   => 'present|array',
            // 認不得的 key 存進去只會變成永遠對不上的死資料
            'notices.*' => ['string', Rule::in(array_values((array) config('constants.NOTICE.PERSONAL')))],
        ];
    }

    public function messages()
    {
        return [
            'notices.present' => trans('account.msg.notice_invalid'),
            'notices.array'   => trans('account.msg.notice_invalid'),
            'notices.*.in'    => trans('account.msg.notice_invalid'),
        ];
    }
}
