<?php

namespace App\Http\Requests\LeaveRequest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 審核撤銷請假
 *
 * 詳見 .claude/knowledge/features/leave-cancel.md
 */
class RespondLeaveCancelRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'status'      => 'required|integer|in:1,2',
            'review_note' => 'nullable|string|max:500',

            /*
             * ⚠⚠ **TEMP-CANCEL-KEEP-QUOTA**
             *
             * 審核視窗那個勾選框。⚠ **一定要 `boolean` 而不是 `nullable`**：
             * 沒帶這個欄位時 Service 會當成 true（還額度），
             * 那是刻意的預設；但**帶了就一定要是明確的真或假** ——
             * 送一個 `"false"` 字串進來卻被當成 true，會讓某個人的特休
             * 莫名其妙多出幾小時，而且不會有任何錯誤訊息。
             */
            'restore_quota' => 'sometimes|boolean',
        ];
    }

    public function messages()
    {
        return [
            'status.required' => trans('leave.msg.cancel_status_invalid'),
            'status.in'       => trans('leave.msg.cancel_status_invalid'),
        ];
    }
}
