<?php

namespace App\Http\Requests\LeaveRequest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 申請撤銷請假
 *
 * ⚠ 這裡只驗「他填的理由」—— 「這張單是不是他的」「撤不撤得掉」
 * 都在 `LeaveCancelService` 判斷：那是業務規則，而且錯的時候要給
 * 看得懂的訊息，不是一句 422。
 *
 * 詳見 .claude/knowledge/features/leave-cancel.md
 */
class StoreLeaveCancelRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'reason' => config('rules.LEAVE_CANCEL_REASON'),
        ];
    }

    public function messages()
    {
        return [
            'reason.max' => trans('leave.msg.cancel_reason_max'),
        ];
    }
}
