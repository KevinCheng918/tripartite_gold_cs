<?php

namespace App\Http\Requests\LeaveRequest;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'user_id'     => 'nullable|integer|exists:user,id',
            'start_date'  => 'required|date',
            'end_date'    => 'nullable|date|after_or_equal:start_date',
            /*
             * ⚠ 假別**不收 `unclassified`**：那是改版前舊資料的標記，
             * 放行的話使用者就有一種「不扣任何額度」的假可以送。
             * 規則放 `config/rules.php`，跟其他驗證一致。
             */
            'type'        => 'required|' . config('rules.LEAVE_TYPE_IN'),
            'is_full_day' => 'required|integer|in:0,1',
            'start_time'  => 'required_if:is_full_day,0|nullable',
            'end_time'    => 'required_if:is_full_day,0|nullable',
            'reason'      => 'nullable|string|max:500',
        ];
    }
}
